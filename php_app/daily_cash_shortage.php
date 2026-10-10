<?php
include 'config.php';
include 'header.php';

/* ═══════════ FILTER PARAMS ═══════════ */
$date_from  = $_GET['date_from']  ?? date('Y-m-d');
$date_to    = $_GET['date_to']    ?? date('Y-m-d');
$view_mode  = $_GET['view_mode']  ?? 'rep_wise';
$f_sr       = trim($_GET['sr_code'] ?? '');
$submitted  = isset($_GET['search']);

$df     = mysqli_real_escape_string($conn, $date_from);
$dt     = mysqli_real_escape_string($conn, $date_to);
$sr_esc = $f_sr ? mysqli_real_escape_string($conn, $f_sr) : '';

/* ═══════════ SR LIST ═══════════ */
$sr_res = mysqli_query($conn, "SELECT DISTINCT sr_code FROM field_summary WHERE sr_code IS NOT NULL AND sr_code!='' ORDER BY sr_code");
$all_sr = [];
if ($sr_res) while ($r = mysqli_fetch_assoc($sr_res)) $all_sr[] = $r['sr_code'];

/* ═══════════ DATA QUERY ═══════════ */
$rows  = [];
$grand = array_fill_keys(['daily_sale','rcvd_credit','rtn_chq','rtn_chgs','sent_back_chq','total_coll','bank_deposit','bo_handover','excess_short','cross_charge_out','cross_charge_in','final_se','emply_chg','chg_to_com'], 0.0);

if ($submitted) {
    $sr_cond_fs = $sr_esc ? " AND fs.sr_code='$sr_esc'" : '';
    $dep_cond   = $sr_esc ? " AND r.rep_code='$sr_esc'" : '';
    $pa_cond    = $sr_esc ? " AND sr_code='$sr_esc'"    : '';
    $tr_cond    = $sr_esc ? " AND (from_sr_code='$sr_esc' OR to_sr_code='$sr_esc')" : '';

    $q_cash = mysqli_query($conn, "
        SELECT ip.payment_date AS del_date, fs.sr_code,
            COALESCE(SUM(CASE WHEN (ip.payment_source IS NULL OR ip.payment_source='' OR LOWER(ip.payment_source)='invoice') THEN ip.amount ELSE 0 END),0) AS daily_sale,
            COALESCE(SUM(CASE WHEN LOWER(ip.payment_source) LIKE '%credit%' THEN ip.amount ELSE 0 END),0) AS rcvd_credit,
            COALESCE(SUM(CASE WHEN ip.payment_source='return_cheque_settlement' THEN ip.amount ELSE 0 END),0) AS rtn_chq,
            COALESCE(SUM(CASE WHEN ip.payment_source='return_charge_settlement' THEN ip.amount ELSE 0 END),0) AS rtn_chgs,
            COALESCE(SUM(CASE WHEN ip.payment_source='sentback_cheque_settlement' THEN ip.amount ELSE 0 END),0) AS sent_back_chq,
            COALESCE(SUM(ip.amount),0) AS total_coll
        FROM invoice_payments ip
        INNER JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
        INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
        WHERE ip.payment_method='cash' AND ip.is_reversed=0
          AND ip.payment_date BETWEEN '$df' AND '$dt'
          $sr_cond_fs
        GROUP BY ip.payment_date, fs.sr_code
        ORDER BY ip.payment_date, fs.sr_code
    ");
    $cash_map = [];
    if ($q_cash) while ($r = mysqli_fetch_assoc($q_cash)) $cash_map[$r['del_date'].'|'.$r['sr_code']] = $r;

    $q_dep = mysqli_query($conn, "
        SELECT r.delivery_date AS del_date, r.rep_code AS sr_code,
            COALESCE(SUM(CASE WHEN d.handed_over_bo=0 THEN r.amount ELSE 0 END),0) AS bank_deposit,
            COALESCE(SUM(CASE WHEN d.handed_over_bo=1 THEN r.amount ELSE 0 END),0) AS bo_handover
        FROM cc_cash_deposit_reps r
        INNER JOIN cc_cash_deposits d ON d.id = r.deposit_id
        WHERE r.delivery_date BETWEEN '$df' AND '$dt'
          AND r.delivery_date IS NOT NULL AND r.delivery_date != '0000-00-00'
          $dep_cond
        GROUP BY r.delivery_date, r.rep_code
    ");
    $dep_map = [];
    if ($q_dep) while ($r = mysqli_fetch_assoc($q_dep)) $dep_map[$r['del_date'].'|'.$r['sr_code']] = $r;

    $q_tr = mysqli_query($conn, "
        SELECT id, from_sr_code, to_sr_code, delivery_date, amount
        FROM cash_shortage_transfers
        WHERE delivery_date BETWEEN '$df' AND '$dt' $tr_cond
    ");
    $tr_out_map = []; $tr_in_map = [];
    if ($q_tr) while ($r = mysqli_fetch_assoc($q_tr)) {
        $kf = $r['delivery_date'].'|'.$r['from_sr_code'];
        $kt = $r['delivery_date'].'|'.$r['to_sr_code'];
        $tr_out_map[$kf] = ($tr_out_map[$kf] ?? 0) + floatval($r['amount']);
        $tr_in_map[$kt]  = ($tr_in_map[$kt]  ?? 0) + floatval($r['amount']);
    }

    $q_pa = mysqli_query($conn, "
        SELECT pay_date, sr_code, entry_type, amount
        FROM cash_summary_pay_allocations
        WHERE pay_date BETWEEN '$df' AND '$dt' $pa_cond
    ");
    $pa_map = [];
    if ($q_pa) while ($r = mysqli_fetch_assoc($q_pa)) {
        $k = $r['pay_date'].'|'.$r['sr_code'];
        if (!isset($pa_map[$k])) $pa_map[$k] = ['emply_chg' => 0, 'chg_to_com' => 0];
        if ($r['entry_type'] === 'charge')  $pa_map[$k]['emply_chg']  += floatval($r['amount']);
        if ($r['entry_type'] === 'absorb')  $pa_map[$k]['chg_to_com'] += floatval($r['amount']);
    }

    $all_keys = array_unique(array_merge(array_keys($cash_map), array_keys($dep_map)));
    sort($all_keys);

    foreach ($all_keys as $k) {
        [$del_date, $sr_code] = explode('|', $k, 2);
        $cm = $cash_map[$k] ?? []; $dm = $dep_map[$k] ?? []; $pa = $pa_map[$k] ?? [];
        $daily_sale    = floatval($cm['daily_sale']    ?? 0);
        $rcvd_credit   = floatval($cm['rcvd_credit']   ?? 0);
        $rtn_chq       = floatval($cm['rtn_chq']       ?? 0);
        $rtn_chgs      = floatval($cm['rtn_chgs']      ?? 0);
        $sent_back_chq = floatval($cm['sent_back_chq'] ?? 0);
        $total_coll    = floatval($cm['total_coll']    ?? 0);
        $bank_deposit  = floatval($dm['bank_deposit']  ?? 0);
        $bo_handover   = floatval($dm['bo_handover']   ?? 0);
        $excess_short  = $total_coll - ($bank_deposit + $bo_handover);
        $cross_out     = floatval($tr_out_map[$k] ?? 0);
        $cross_in      = floatval($tr_in_map[$k]  ?? 0);
        $final_se      = ($excess_short >= 0) ? ($excess_short - $cross_out + $cross_in) : ($excess_short + $cross_out - $cross_in);
        $emply_chg     = floatval($pa['emply_chg']  ?? 0);
        $chg_to_com    = floatval($pa['chg_to_com'] ?? 0);

        $rows[] = compact('del_date','sr_code','daily_sale','rcvd_credit','rtn_chq','rtn_chgs',
            'sent_back_chq','total_coll','bank_deposit','bo_handover',
            'excess_short','cross_out','cross_in','final_se','emply_chg','chg_to_com');

        $grand['daily_sale']       += $daily_sale;   $grand['rcvd_credit']     += $rcvd_credit;
        $grand['rtn_chq']          += $rtn_chq;      $grand['rtn_chgs']        += $rtn_chgs;
        $grand['sent_back_chq']    += $sent_back_chq;$grand['total_coll']      += $total_coll;
        $grand['bank_deposit']     += $bank_deposit; $grand['bo_handover']     += $bo_handover;
        $grand['excess_short']     += $excess_short; $grand['cross_charge_out']+= $cross_out;
        $grand['cross_charge_in']  += $cross_in;     $grand['final_se']        += $final_se;
        $grand['emply_chg']        += $emply_chg;    $grand['chg_to_com']      += $chg_to_com;
    }

    if ($view_mode === 'rep_wise') {
        $grouped = [];
        foreach ($rows as $row) {
            $sr = $row['sr_code'];
            if (!isset($grouped[$sr])) {
                $grouped[$sr] = [
                    'sr_code'=>$sr,'date_range'=>[],'daily_sale'=>0,'rcvd_credit'=>0,
                    'rtn_chq'=>0,'rtn_chgs'=>0,'sent_back_chq'=>0,'total_coll'=>0,
                    'bank_deposit'=>0,'bo_handover'=>0,'excess_short'=>0,'cross_out'=>0,
                    'cross_in'=>0,'final_se'=>0,'emply_chg'=>0,'chg_to_com'=>0,'details'=>[]
                ];
            }
            $grouped[$sr]['date_range'][]   = $row['del_date'];
            $grouped[$sr]['daily_sale']     += $row['daily_sale'];
            $grouped[$sr]['rcvd_credit']    += $row['rcvd_credit'];
            $grouped[$sr]['rtn_chq']        += $row['rtn_chq'];
            $grouped[$sr]['rtn_chgs']       += $row['rtn_chgs'];
            $grouped[$sr]['sent_back_chq']  += $row['sent_back_chq'];
            $grouped[$sr]['total_coll']     += $row['total_coll'];
            $grouped[$sr]['bank_deposit']   += $row['bank_deposit'];
            $grouped[$sr]['bo_handover']    += $row['bo_handover'];
            $grouped[$sr]['excess_short']   += $row['excess_short'];
            $grouped[$sr]['cross_out']      += $row['cross_out'];
            $grouped[$sr]['cross_in']       += $row['cross_in'];
            $grouped[$sr]['final_se']       += $row['final_se'];
            $grouped[$sr]['emply_chg']      += $row['emply_chg'];
            $grouped[$sr]['chg_to_com']     += $row['chg_to_com'];
            $grouped[$sr]['details'][]       = $row;
        }
        foreach ($grouped as &$g) {
            $g['date_range'] = array_unique($g['date_range']); sort($g['date_range']);
            $g['cross_charge'] = ($g['cross_out']>0||$g['cross_in']>0) ? ['out'=>$g['cross_out'],'in'=>$g['cross_in']] : null;
        }
        unset($g);
    }
}

/* ═══════════ HELPERS ═══════════ */
function ds_v($v){$n=floatval($v);return $n==0?'<span class="ds-dash">—</span>':'<span class="ds-num">'.number_format($n,2).'</span>';}
function ds_se($v){$n=floatval($v);if(abs($n)<0.005)return '<span class="ds-ok">0.00 ✓</span>';if($n<0)return '<span class="ds-exc">▲ '.number_format(abs($n),2).'</span>';return '<span class="ds-sht">▼ '.number_format($n,2).'</span>';}
function ds_cross($row){$out=floatval($row['cross_out']??0);$in=floatval($row['cross_in']??0);if($out==0&&$in==0)return '<span class="ds-dash">—</span>';$html='';if($out>0)$html.='<span class="ds-out">▼'.number_format($out,2).'</span>';if($out>0&&$in>0)$html.='<br>';if($in>0)$html.='<span class="ds-in">▲'.number_format($in,2).'</span>';return $html;}
function ds_t($v){$n=floatval($v);return $n==0?'—':number_format($n,2);}
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap');
:root {
  --bg:#eef0f3;--surface:#fff;--bdr:#d2d6db;--bdrs:#e4e7ec;
  --tx:#1a1f2e;--txm:#58626e;--txs:#9aa3ad;
  --fn:'Inter',sans-serif;--mn:'JetBrains Mono',monospace;--r:8px;
  --sh:0 1px 3px rgba(0,0,0,.07),0 4px 14px rgba(0,0,0,.05);
  --total-bg:#0f172a;
  --h-main:#1e3a5f;--h-grn:#14532d;--h-dep:#1e4d8c;
  --h-sht:#7f1d1d;--h-tr:#0c4a6e;--h-new:#312e81;--h-pay:#5b21b6;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--fn);background:var(--bg);color:var(--tx);font-size:12px;}
.pg{padding:12px 12px 40px;}
.topbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:10px;}
.pg-h1{font-size:19px;font-weight:800;letter-spacing:-.02em;}
.pg-h1 em{color:#dc2626;font-style:normal;}
.pg-sub{font-size:10px;color:var(--txs);margin-top:2px;}
.dpill{display:inline-flex;align-items:center;gap:5px;background:#fef2f2;border:1px solid #fca5a5;border-radius:20px;padding:4px 11px;font-size:11px;font-weight:700;color:#991b1b;font-family:var(--mn);}
.fbar{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);padding:8px 12px;margin-bottom:10px;display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap;box-shadow:var(--sh);}
.fg{display:flex;flex-direction:column;gap:2px;}
.fg label{font-size:10px;font-weight:700;color:var(--txs);text-transform:uppercase;letter-spacing:.07em;}
.fg input,.fg select{padding:6px 9px;border:1.5px solid var(--bdr);border-radius:6px;font-size:12px;font-family:var(--fn);color:var(--tx);background:#fff;}
.fg input:focus,.fg select:focus{outline:none;border-color:#dc2626;}
.view-toggle{display:flex;background:#f1f5f9;border:1px solid var(--bdr);border-radius:7px;overflow:hidden;}
.view-toggle label{padding:6px 13px;font-size:11px;font-weight:600;cursor:pointer;color:var(--txm);transition:all .15s;user-select:none;}
.view-toggle input[type=radio]{display:none;}
.view-toggle input[type=radio]:checked + label{background:#1e3a5f;color:#fff;}
.btn-go{display:inline-flex;align-items:center;gap:5px;padding:7px 16px;background:#1e3a5f;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;font-family:var(--fn);cursor:pointer;}
.btn-go:hover{background:#2d4f7a;}
.btn-rst{display:inline-flex;align-items:center;gap:4px;padding:7px 11px;background:#f0f0f0;color:var(--txm);border:1px solid var(--bdr);border-radius:6px;font-size:12px;font-weight:600;font-family:var(--fn);cursor:pointer;text-decoration:none;}
.btn-excel{display:inline-flex;align-items:center;gap:5px;padding:7px 13px;background:linear-gradient(135deg,#166534,#15803d);color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;font-family:var(--fn);cursor:pointer;box-shadow:0 2px 6px rgba(22,101,52,.3);}
.btn-excel:hover{background:linear-gradient(135deg,#14532d,#166534);}
/* ── SAVE button ── */
.btn-save{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;font-family:var(--fn);cursor:pointer;box-shadow:0 2px 6px rgba(109,40,217,.3);}
.btn-save:hover{background:linear-gradient(135deg,#6d28d9,#5b21b6);}
.btn-saved-list{display:inline-flex;align-items:center;gap:5px;padding:7px 13px;background:linear-gradient(135deg,#0369a1,#0284c7);color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;font-family:var(--fn);cursor:pointer;box-shadow:0 2px 6px rgba(3,105,161,.3);}
.btn-saved-list:hover{background:linear-gradient(135deg,#075985,#0369a1);}
/* ── summary cards ── */
.sc-row{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-bottom:10px;}
.sc{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);padding:8px 10px;box-shadow:var(--sh);border-left:3px solid #e5e7eb;}
.sc.navy{border-left-color:#1e3a5f;}.sc.green{border-left-color:#22c55e;}.sc.blue{border-left-color:#3b82f6;}.sc.red{border-left-color:#ef4444;}.sc.indigo{border-left-color:#6366f1;}
.sc-lbl{font-size:9px;font-weight:700;color:var(--txs);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;}
.sc-val{font-size:13px;font-weight:800;}
/* ── table ── */
.tc{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);overflow:hidden;box-shadow:var(--sh);}
.tc-bar{display:flex;justify-content:space-between;align-items:center;padding:7px 11px;border-bottom:1px solid var(--bdrs);flex-wrap:wrap;gap:6px;}
.tc-ttl{font-size:13px;font-weight:700;display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.pill{padding:1px 7px;border-radius:12px;font-size:10px;font-weight:700;}
.p-red{background:#fee2e2;color:#991b1b;}.p-blue{background:#dbeafe;color:#1e40af;}.p-slate{background:#f1f5f9;color:#475569;}.p-green{background:#dcfce7;color:#166534;}.p-purple{background:#ede9fe;color:#5b21b6;}
.top-scroll-wrap{overflow-x:auto;overflow-y:hidden;height:10px;margin-bottom:1px;}
.top-scroll-inner{height:1px;}
.tscroll{overflow-x:auto;}
table.dst{width:100%;border-collapse:collapse;font-size:11px;}
.dst .G th{padding:4px 5px;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#fff;text-align:center;white-space:nowrap;border-right:2px solid rgba(255,255,255,.18);}
.dst .G th:last-child{border-right:none;}
.dst .G th.tl{text-align:left;}
.dst .S th{padding:3px 5px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:rgba(255,255,255,.88);text-align:center;white-space:nowrap;border-right:1px solid rgba(255,255,255,.1);border-bottom:2px solid var(--bdr);}
.dst .S th.tl{text-align:left;}.dst .S th:last-child{border-right:none;}
.h-main{background:var(--h-main);}.h-coll{background:var(--h-grn);}.h-dep{background:var(--h-dep);}.h-sht{background:var(--h-sht);}.h-tr{background:var(--h-tr);}.h-new{background:var(--h-new);}.h-pay{background:var(--h-pay);}
.s-main{background:#162d4a;}.s-coll{background:#0f3d20;}.s-dep{background:#163a6e;}.s-sht{background:#6b1616;}.s-tr{background:#063b58;}.s-new{background:#26235a;}.s-pay{background:#4c1d95;}
.dst .TH td{background:#e0edff;color:#1e3a8a;font-weight:800;font-size:10.5px;padding:4px 5px;text-align:right;border-bottom:2px solid #93c5fd;white-space:nowrap;font-family:var(--mn);}
.dst .TH td.tl{text-align:left;font-family:var(--fn);}
.dst tbody tr td{background:#fff;}
.dst tbody tr.stripe td{background:#fafbfc;}
.dst tbody tr:hover td{background:#eff6ff!important;}
.dst tbody td{padding:3px 5px;text-align:right;white-space:nowrap;}
.dst tbody td.tl{text-align:left;}
.dst tbody td.dt{font-family:var(--mn);font-size:10px;color:var(--txm);}
.dst tbody td.rc{font-weight:700;font-size:11px;}
.dst tbody td.col-coll{background:rgba(20,83,45,.05);}
.dst tbody tr.stripe td.col-coll{background:rgba(20,83,45,.09);}
.dst tbody tr:hover td.col-coll{background:#f0fdf4!important;}
.dst tbody td.col-dep{background:rgba(30,77,140,.05);}
.dst tbody tr.stripe td.col-dep{background:rgba(30,77,140,.09);}
.dst tbody tr:hover td.col-dep{background:#eff6ff!important;}
.dst tbody td.col-sht{background:rgba(127,29,29,.05);}
.dst tbody tr.stripe td.col-sht{background:rgba(127,29,29,.09);}
.dst tbody tr:hover td.col-sht{background:#fef2f2!important;}
.dst tbody td.col-tr{background:rgba(12,74,110,.05);}
.dst tbody tr.stripe td.col-tr{background:rgba(12,74,110,.09);}
.dst tbody tr:hover td.col-tr{background:#e0f2fe!important;}
.dst tbody td.col-new{background:rgba(49,46,129,.06);}
.dst tbody tr.stripe td.col-new{background:rgba(49,46,129,.10);}
.dst tbody tr:hover td.col-new{background:#ede9fe!important;}
.dst tbody td.col-pay{background:#fdf4ff;}
.dst tbody tr.stripe td.col-pay{background:#f5e9ff;}
.dst tbody tr:hover td.col-pay{background:#f0e6ff!important;}
.dst tfoot td{padding:4px 5px;font-weight:800;font-size:11px;background:var(--total-bg);color:#e2e8f0;border-top:2px solid #334155;text-align:right;white-space:nowrap;font-family:var(--mn);}
.dst tfoot td.tl{text-align:left;color:#94a3b8;font-family:var(--fn);}
.dst td.stk,.dst th.stk{position:sticky;left:0;z-index:2;box-shadow:3px 0 8px rgba(0,0,0,.10);}
.dst thead th.stk{z-index:4;}
.dst .G th.stk{background:var(--h-main);}.dst .S th.stk{background:#162d4a;}
.dst .TH td.stk{background:#e0edff;}.dst tfoot td.stk{background:var(--total-bg);}
.dst tbody tr td.stk{background:#fff;}.dst tbody tr.stripe td.stk{background:#fafbfc;}
.dst tbody tr:hover td.stk{background:#eff6ff!important;}
.ds-num{font-family:var(--mn);font-size:10.5px;}
.ds-dash{color:#d1d5db;}
.ds-ok{color:#16a34a;font-weight:700;font-family:var(--mn);font-size:10px;}
.ds-exc{color:#16a34a;font-weight:700;font-family:var(--mn);font-size:10px;}
.ds-sht{color:#dc2626;font-weight:700;font-family:var(--mn);font-size:10px;}
.ds-out{color:#ef4444;font-weight:700;font-family:var(--mn);font-size:10.5px;}
.ds-in{color:#22c55e;font-weight:700;font-family:var(--mn);font-size:10.5px;}
.ds-charge{color:#dc2626;font-weight:600;}
.ds-absorb{color:#0369a1;font-weight:600;}
.grp-row td{background:#f0f4ff!important;border-top:2px solid #bfdbfe;border-bottom:1px solid #bfdbfe;}
.grp-row td.tl{font-weight:800;color:#1e3a5f;}
.exp-btn{background:none;border:1px solid #bfdbfe;border-radius:5px;width:22px;height:22px;cursor:pointer;font-size:11px;color:#1e40af;display:inline-flex;align-items:center;justify-content:center;transition:all .15s;}
.exp-btn:hover{background:#dbeafe;}
.det-row td{background:#f9fbff!important;font-size:10.5px;}
.det-row td.stk{background:#f9fbff!important;}
.empty{text-align:center;padding:50px 20px;color:var(--txs);}
.empty i{font-size:38px;display:block;margin-bottom:10px;opacity:.25;}

/* ═══════ MODAL BASE ═══════ */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:8000;display:none;align-items:center;justify-content:center;backdrop-filter:blur(3px);}
.modal-overlay.open{display:flex;}
.modal-box{background:#fff;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.3);width:90%;max-width:520px;overflow:hidden;animation:modalIn .2s ease;}
.modal-box.wide{max-width:900px;}
@keyframes modalIn{from{transform:scale(.95) translateY(-10px);opacity:0;}to{transform:scale(1) translateY(0);opacity:1;}}
.modal-head{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--bdrs);}
.modal-head h3{font-size:14px;font-weight:800;display:flex;align-items:center;gap:8px;}
.modal-close{background:none;border:none;font-size:18px;cursor:pointer;color:var(--txm);padding:2px 6px;border-radius:5px;}
.modal-close:hover{background:#f1f5f9;}
.modal-body{padding:18px;}
.modal-foot{padding:12px 18px;border-top:1px solid var(--bdrs);display:flex;justify-content:flex-end;gap:8px;}

/* ── Save Modal ── */
.sm-field{display:flex;flex-direction:column;gap:5px;margin-bottom:14px;}
.sm-field label{font-size:11px;font-weight:700;color:var(--txm);}
.sm-field input,.sm-field textarea,.sm-field select{padding:8px 10px;border:1.5px solid var(--bdr);border-radius:6px;font-size:12px;font-family:var(--fn);color:var(--tx);}
.sm-field input:focus,.sm-field textarea:focus{outline:none;border-color:#7c3aed;}
.sm-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;background:#f8fafc;border-radius:8px;padding:10px 12px;border:1px solid var(--bdrs);margin-bottom:14px;}
.sm-info-item{display:flex;flex-direction:column;gap:2px;}
.sm-info-item .lbl{font-size:9px;font-weight:700;color:var(--txs);text-transform:uppercase;}
.sm-info-item .val{font-size:12px;font-weight:600;color:var(--tx);}

/* ── Saved Reports List Modal ── */
.sr-list{display:flex;flex-direction:column;gap:6px;max-height:450px;overflow-y:auto;padding-right:2px;}
.sr-item{background:#f8fafc;border:1px solid var(--bdr);border-radius:8px;padding:10px 13px;display:flex;align-items:center;gap:10px;transition:all .15s;}
.sr-item:hover{background:#eff6ff;border-color:#bfdbfe;}
.sr-item-icon{width:36px;height:36px;background:linear-gradient(135deg,#7c3aed,#6d28d9);border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:15px;flex-shrink:0;}
.sr-item-body{flex:1;min-width:0;}
.sr-item-name{font-size:12px;font-weight:700;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.sr-item-meta{font-size:10px;color:var(--txm);margin-top:2px;display:flex;flex-wrap:wrap;gap:6px;}
.sr-item-meta span{display:inline-flex;align-items:center;gap:3px;}
.sr-item-actions{display:flex;gap:5px;flex-shrink:0;}
.btn-view-rep{padding:4px 10px;background:#1e3a5f;color:#fff;border:none;border-radius:5px;font-size:10px;font-weight:700;cursor:pointer;font-family:var(--fn);}
.btn-view-rep:hover{background:#2d4f7a;}
.btn-del-rep{padding:4px 8px;background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;border-radius:5px;font-size:10px;font-weight:700;cursor:pointer;font-family:var(--fn);}
.btn-del-rep:hover{background:#fecaca;}
.sr-empty{text-align:center;padding:30px;color:var(--txs);}
.sr-search{width:100%;padding:7px 10px;border:1.5px solid var(--bdr);border-radius:6px;font-size:12px;font-family:var(--fn);margin-bottom:10px;}
.sr-search:focus{outline:none;border-color:#0369a1;}

/* ── Saved Report Viewer Modal ── */
.srv-meta{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;padding:10px 12px;background:#f0f4ff;border-radius:8px;border:1px solid #bfdbfe;}
.srv-meta span{font-size:11px;font-weight:600;color:#1e40af;display:inline-flex;align-items:center;gap:4px;}
.srv-table-wrap{overflow-x:auto;max-height:360px;overflow-y:auto;}
.srv-tbl{width:100%;border-collapse:collapse;font-size:10.5px;}
.srv-tbl th{background:#1e3a5f;color:#fff;padding:4px 7px;white-space:nowrap;font-size:9.5px;text-align:center;position:sticky;top:0;z-index:2;}
.srv-tbl th:first-child,.srv-tbl th:nth-child(2){text-align:left;}
.srv-tbl td{padding:3px 7px;border-bottom:1px solid #f1f5f9;white-space:nowrap;text-align:right;font-family:var(--mn);}
.srv-tbl td:first-child,.srv-tbl td:nth-child(2){text-align:left;font-family:var(--fn);}
.srv-tbl tr:hover td{background:#eff6ff;}
.srv-tbl tfoot td{background:#0f172a;color:#e2e8f0;font-weight:800;font-size:10.5px;padding:4px 7px;border-top:2px solid #334155;}
.srv-tbl tfoot td:first-child{text-align:left;color:#94a3b8;}

/* ── loading ── */
.spin-overlay{position:fixed;inset:0;background:rgba(255,255,255,.7);z-index:9500;display:none;align-items:center;justify-content:center;}
.spin-overlay.open{display:flex;}
.spin-box{background:#fff;border-radius:12px;padding:20px 30px;box-shadow:0 8px 30px rgba(0,0,0,.15);display:flex;align-items:center;gap:12px;font-size:13px;font-weight:600;color:var(--tx);}

/* ── toast ── */
#dsToast{position:fixed;bottom:20px;right:20px;padding:9px 16px;border-radius:8px;font-size:12px;font-weight:600;z-index:9999;display:none;opacity:0;transition:opacity .3s;}
.toast-ok{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.toast-err{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.toast-info{background:#dbeafe;color:#1e40af;border:1px solid #93c5fd;}

/* ── print ── */
.print-header{display:none;}
@media print{
  body{background:#fff;font-size:9px;}
  .no-print{display:none!important;}
  .pg{padding:4px;}
  .print-header{display:block;text-align:center;padding:6px 0;border-bottom:2px solid #1e3a5f;margin-bottom:6px;}
  .print-header h2{font-size:14px;font-weight:800;}
  table.dst{font-size:8px;}
  .dst .G th,.dst .S th,.dst tfoot td{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .dst td.stk,.dst th.stk{position:static;box-shadow:none;}
  .top-scroll-wrap{display:none;}
}
</style>

<div class="pg">

<div class="print-header">
  <h2>Daily Cash Shortage Report</h2>
  <p><?php echo date('d M Y',strtotime($date_from)).' — '.date('d M Y',strtotime($date_to));
     echo $f_sr ? ' · Rep: '.htmlspecialchars($f_sr) : ' · All Reps';
     echo ' · '.($view_mode==='rep_wise'?'Rep-Wise (Grouped)':'Rep Code Wise');
     echo ' · Generated: '.date('d M Y H:i');?></p>
</div>

<div class="topbar no-print">
  <div>
    <div class="pg-h1">Daily Cash <em>Shortage</em></div>
    <div class="pg-sub">Cash Collected · Deposits · Excess/Short · Cross Charge · Final Balance · Pay Allocation</div>
  </div>
  <div style="display:flex;gap:7px;align-items:center;flex-wrap:wrap;">
    <div class="dpill"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo date('d M Y',strtotime($date_from)).' — '.date('d M Y',strtotime($date_to));?></div>

    <!-- Saved Reports List Button (always visible) – links to dedicated page -->
    <a href="view_saved_cash_reports.php" class="btn-saved-list" title="View All Saved Reports">
      <i class="fa-solid fa-folder-open"></i> Saved Reports
    </a>

    <?php $has_data = $submitted && (($view_mode==='repcode_wise'&&!empty($rows))||($view_mode==='rep_wise'&&!empty($grouped??[]))); ?>
    <?php if ($has_data): ?>
    <!-- Save Report Button -->
    <button onclick="openSaveModal()" class="btn-save">
      <i class="fa-solid fa-floppy-disk"></i> Save Report
    </button>
    <button onclick="exportToExcel()" class="btn-excel"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
    <button onclick="window.print()" class="btn-rst"><i class="fa-solid fa-print"></i> Print</button>
    <?php endif; ?>
  </div>
</div>

<!-- FILTER BAR -->
<div class="fbar no-print">
  <form method="GET" id="dsf" style="display:contents;">
    <input type="hidden" name="search" value="1">
    <div class="fg">
      <label><i class="fa-solid fa-calendar-day"></i> Date From</label>
      <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from);?>">
    </div>
    <div class="fg">
      <label><i class="fa-solid fa-calendar-day"></i> Date To</label>
      <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to);?>">
    </div>
    <div class="fg">
      <label><i class="fa-solid fa-layer-group"></i> View Mode</label>
      <div class="view-toggle">
        <input type="radio" name="view_mode" id="vm_rw" value="rep_wise" <?php echo $view_mode==='rep_wise'?'checked':'';?>>
        <label for="vm_rw"><i class="fa-solid fa-users"></i> Rep Wise</label>
        <input type="radio" name="view_mode" id="vm_rc" value="repcode_wise" <?php echo $view_mode==='repcode_wise'?'checked':'';?>>
        <label for="vm_rc"><i class="fa-solid fa-id-badge"></i> Rep Code Wise</label>
      </div>
    </div>
    <?php if ($view_mode==='repcode_wise'): ?>
    <div class="fg" style="min-width:160px;">
      <label><i class="fa-solid fa-id-badge"></i> Sales Rep Code</label>
      <select name="sr_code">
        <option value="">— All Reps —</option>
        <?php foreach($all_sr as $sr):?><option value="<?php echo htmlspecialchars($sr);?>" <?php echo $f_sr===$sr?'selected':'';?>><?php echo htmlspecialchars($sr);?></option><?php endforeach;?>
      </select>
    </div>
    <?php endif; ?>
    <button type="submit" class="btn-go" id="gBtn"><i class="fa-solid fa-magnifying-glass"></i> Generate</button>
    <a href="daily_cash_shortage.php" class="btn-rst" title="Reset"><i class="fa-solid fa-rotate-left"></i></a>
  </form>
</div>

<?php if (!$submitted): ?>
<div class="tc"><div class="empty"><i class="fa-solid fa-triangle-exclamation"></i>
  <p style="font-size:14px;font-weight:600;margin-bottom:5px;">Daily Cash Shortage Report</p>
  <p>Choose a date range, select a view mode, and click <strong>Generate</strong>.</p>
</div></div>

<?php elseif (!$has_data): ?>
<div class="tc"><div class="empty"><i class="fa-solid fa-inbox"></i>
  <p style="font-size:14px;font-weight:600;margin-bottom:5px;">No records found</p>
  <p><?php echo date('d M Y',strtotime($date_from)).' – '.date('d M Y',strtotime($date_to));
     echo $f_sr?' · Rep: <strong>'.htmlspecialchars($f_sr).'</strong>':'';?></p>
</div></div>

<?php else: ?>

<!-- SUMMARY CARDS -->
<div class="sc-row no-print">
  <div class="sc navy"><div class="sc-lbl"><i class="fa-solid fa-money-bill-wave"></i> Total Collections</div><div class="sc-val" style="color:#1e3a5f;">Rs. <?php echo number_format($grand['total_coll'],2);?></div></div>
  <div class="sc blue"><div class="sc-lbl"><i class="fa-solid fa-building-columns"></i> Bank Deposit</div><div class="sc-val" style="color:#1e40af;">Rs. <?php echo number_format($grand['bank_deposit'],2);?></div></div>
  <div class="sc green"><div class="sc-lbl"><i class="fa-solid fa-handshake"></i> BO Hand Over</div><div class="sc-val" style="color:#166534;">Rs. <?php echo number_format($grand['bo_handover'],2);?></div></div>
  <?php $g_se=$grand['excess_short']; ?>
  <div class="sc <?php echo $g_se>0?'red':'green';?>">
    <div class="sc-lbl"><i class="fa-solid fa-scale-unbalanced"></i> Excess / Short</div>
    <div class="sc-val" style="color:<?php echo $g_se>0?'#991b1b':'#166534';?>;">
      Rs. <?php echo number_format(abs($g_se),2);?> <?php echo $g_se<0?'<small style="font-size:10px;">(Excess)</small>':($g_se==0?'<small>(OK)</small>':'');?>
    </div>
  </div>
  <?php $g_fse=$grand['final_se']; ?>
  <div class="sc indigo">
    <div class="sc-lbl"><i class="fa-solid fa-flag-checkered"></i> Final Short/Excess</div>
    <div class="sc-val" style="color:<?php echo $g_fse>0?'#991b1b':($g_fse<0?'#166534':'#374151');?>;">
      Rs. <?php echo number_format(abs($g_fse),2);?> <?php echo $g_fse<0?'<small style="font-size:10px;">(Excess)</small>':($g_fse==0?'<small>(OK)</small>':'');?>
    </div>
  </div>
</div>

<div class="tc">
  <div class="tc-bar no-print">
    <div class="tc-ttl">
      <i class="fa-solid fa-table"></i> Daily Cash Shortage
      <?php if($view_mode==='rep_wise'):?>
      <span class="pill p-blue"><i class="fa-solid fa-users"></i> Rep-Wise (Grouped)</span>
      <?php else:?>
      <span class="pill p-slate"><i class="fa-solid fa-id-badge"></i> Rep Code Wise</span>
      <?php endif;?>
      <span class="pill p-red"><?php echo $view_mode==='rep_wise'?count($grouped??[]):count($rows);?> <?php echo $view_mode==='rep_wise'?'reps':'records';?></span>
      <span class="pill p-blue"><?php echo date('d M Y',strtotime($date_from)).' – '.date('d M Y',strtotime($date_to));?></span>
      <?php if($f_sr&&$view_mode==='repcode_wise'):?><span class="pill p-slate">Rep: <?php echo htmlspecialchars($f_sr);?></span><?php endif;?>
      <span style="font-size:10px;color:var(--txs);">· <span style="color:#16a34a;font-weight:600;">▲ Excess</span>&nbsp;<span style="color:#dc2626;font-weight:600;">▼ Short</span></span>
    </div>
  </div>
  <div class="top-scroll-wrap no-print" id="topScroll"><div class="top-scroll-inner" id="topScrollInner"></div></div>
  <div class="tscroll" id="mainScroll">
  <table class="dst" id="dstMain">
    <thead>
      <tr class="G">
        <th class="h-main tl stk" rowspan="2" style="min-width:90px;">Rep Code</th>
        <th class="h-main tl" rowspan="2" style="min-width:90px;"><?php echo $view_mode==='rep_wise'?'Date Range':'Del. Date';?></th>
        <th class="h-coll" colspan="6">Cash Collections</th>
        <th class="h-dep"  colspan="2">Deposits</th>
        <th class="h-sht"  colspan="1">Short / Excess</th>
        <th class="h-tr"   colspan="1">Cross Charge</th>
        <th class="h-new"  colspan="1">Final (Ex/Short)</th>
        <th class="h-pay"  colspan="2">Pay Allocation</th>
      </tr>
      <tr class="S">
        <th class="s-coll" style="min-width:95px;">Daily Cash Sale</th>
        <th class="s-coll" style="min-width:90px;">Rcvd Credit</th>
        <th class="s-coll" style="min-width:85px;">RTN Chqs</th>
        <th class="s-coll" style="min-width:82px;">RTN Chgs</th>
        <th class="s-coll" style="min-width:90px;">Sent Back Chq</th>
        <th class="s-coll" style="min-width:98px;">Total Collections</th>
        <th class="s-dep"  style="min-width:95px;">Bank Deposit</th>
        <th class="s-dep"  style="min-width:95px;">BO Hand Over</th>
        <th class="s-sht"  style="min-width:92px;">Excess/Short</th>
        <th class="s-tr"   style="min-width:110px;">Cross Charge</th>
        <th class="s-new"  style="min-width:100px;">Final (Ex/Short)</th>
        <th class="s-pay"  style="min-width:90px;">Emply Chg</th>
        <th class="s-pay"  style="min-width:90px;">Chg to Com</th>
      </tr>
      <tr class="TH">
        <td class="tl stk" colspan="2"><i class="fa-solid fa-sigma"></i>&nbsp; Total</td>
        <td><?php echo ds_t($grand['daily_sale']);?></td>
        <td><?php echo ds_t($grand['rcvd_credit']);?></td>
        <td><?php echo ds_t($grand['rtn_chq']);?></td>
        <td><?php echo ds_t($grand['rtn_chgs']);?></td>
        <td><?php echo ds_t($grand['sent_back_chq']);?></td>
        <td style="color:#15803d;font-weight:800;"><?php echo ds_t($grand['total_coll']);?></td>
        <td><?php echo ds_t($grand['bank_deposit']);?></td>
        <td><?php echo ds_t($grand['bo_handover']);?></td>
        <td><?php echo ds_se($grand['excess_short']);?></td>
        <td>
          <?php if($grand['cross_charge_out']>0||$grand['cross_charge_in']>0):?>
            <?php if($grand['cross_charge_out']>0):?><span class="ds-out">▼<?php echo number_format($grand['cross_charge_out'],2);?></span><?php endif;?>
            <?php if($grand['cross_charge_out']>0&&$grand['cross_charge_in']>0):?> · <?php endif;?>
            <?php if($grand['cross_charge_in']>0):?><span class="ds-in">▲<?php echo number_format($grand['cross_charge_in'],2);?></span><?php endif;?>
          <?php else:?>—<?php endif;?>
        </td>
        <td><?php echo ds_se($grand['final_se']);?></td>
        <td style="color:#dc2626;"><?php echo ds_t($grand['emply_chg']);?></td>
        <td style="color:#0369a1;"><?php echo ds_t($grand['chg_to_com']);?></td>
      </tr>
    </thead>
    <tbody>

    <?php if ($view_mode === 'repcode_wise'): ?>
    <?php foreach ($rows as $i => $r): $stripe=($i%2!==0)?'stripe':'';?>
    <tr class="<?php echo $stripe;?>">
      <td class="tl rc stk"><?php echo htmlspecialchars($r['sr_code']);?></td>
      <td class="tl dt"><?php echo date('d M Y',strtotime($r['del_date']));?></td>
      <td class="col-coll"><?php echo ds_v($r['daily_sale']);?></td>
      <td class="col-coll"><?php echo ds_v($r['rcvd_credit']);?></td>
      <td class="col-coll"><?php echo ds_v($r['rtn_chq']);?></td>
      <td class="col-coll"><?php echo ds_v($r['rtn_chgs']);?></td>
      <td class="col-coll"><?php echo ds_v($r['sent_back_chq']);?></td>
      <td class="col-coll" style="font-weight:800;"><?php echo ds_v($r['total_coll']);?></td>
      <td class="col-dep"><?php echo ds_v($r['bank_deposit']);?></td>
      <td class="col-dep"><?php echo ds_v($r['bo_handover']);?></td>
      <td class="col-sht"><?php echo ds_se($r['excess_short']);?></td>
      <td class="col-tr"><?php echo ds_cross($r);?></td>
      <td class="col-new"><?php echo ds_se($r['final_se']);?></td>
      <td class="col-pay"><?php echo $r['emply_chg']>0?'<span class="ds-charge">'.number_format($r['emply_chg'],2).'</span>':'<span class="ds-dash">—</span>';?></td>
      <td class="col-pay"><?php echo $r['chg_to_com']>0?'<span class="ds-absorb">'.number_format($r['chg_to_com'],2).'</span>':'<span class="ds-dash">—</span>';?></td>
    </tr>
    <?php endforeach; ?>

    <?php else: ?>
    <?php $gi=0; foreach ($grouped as $sr_code => $g): $stripe=($gi%2!==0)?'stripe':''; $gi++; ?>
    <tr class="grp-row" id="grp-<?php echo htmlspecialchars($sr_code);?>">
      <td class="tl stk" style="font-weight:800;color:#1e3a5f;">
        <button class="exp-btn" onclick="toggleGroup('<?php echo addslashes($sr_code);?>')" id="expbtn-<?php echo addslashes($sr_code);?>">
          <i class="fa-solid fa-chevron-down" id="expico-<?php echo addslashes($sr_code);?>"></i>
        </button>
        <?php echo htmlspecialchars($sr_code);?>
      </td>
      <td class="tl dt" style="font-size:10px;color:#374151;">
        <?php $dr=$g['date_range'];
        if(count($dr)===1)echo date('d M Y',strtotime($dr[0]));
        else echo date('d M Y',strtotime($dr[0])).' – '.date('d M Y',strtotime(end($dr)));?>
        <span class="pill p-slate" style="margin-left:4px;"><?php echo count($g['details']);?> days</span>
      </td>
      <td class="col-coll" style="font-weight:700;"><?php echo ds_v($g['daily_sale']);?></td>
      <td class="col-coll" style="font-weight:700;"><?php echo ds_v($g['rcvd_credit']);?></td>
      <td class="col-coll" style="font-weight:700;"><?php echo ds_v($g['rtn_chq']);?></td>
      <td class="col-coll" style="font-weight:700;"><?php echo ds_v($g['rtn_chgs']);?></td>
      <td class="col-coll" style="font-weight:700;"><?php echo ds_v($g['sent_back_chq']);?></td>
      <td class="col-coll" style="font-weight:800;color:#166534;"><?php echo ds_v($g['total_coll']);?></td>
      <td class="col-dep"  style="font-weight:700;"><?php echo ds_v($g['bank_deposit']);?></td>
      <td class="col-dep"  style="font-weight:700;"><?php echo ds_v($g['bo_handover']);?></td>
      <td class="col-sht"  style="font-weight:700;"><?php echo ds_se($g['excess_short']);?></td>
      <td class="col-tr"><?php echo ds_cross($g);?></td>
      <td class="col-new"  style="font-weight:700;"><?php echo ds_se($g['final_se']);?></td>
      <td class="col-pay"><?php echo $g['emply_chg']>0?'<span class="ds-charge" style="font-weight:700;">'.number_format($g['emply_chg'],2).'</span>':'<span class="ds-dash">—</span>';?></td>
      <td class="col-pay"><?php echo $g['chg_to_com']>0?'<span class="ds-absorb" style="font-weight:700;">'.number_format($g['chg_to_com'],2).'</span>':'<span class="ds-dash">—</span>';?></td>
    </tr>
    <?php foreach ($g['details'] as $d): ?>
    <tr class="det-row det-<?php echo htmlspecialchars($sr_code);?>" style="display:none;">
      <td class="tl stk" style="padding-left:24px;color:var(--txm);font-size:10.5px;">└ <?php echo htmlspecialchars($d['sr_code']);?></td>
      <td class="tl dt"><?php echo date('d M Y',strtotime($d['del_date']));?></td>
      <td class="col-coll"><?php echo ds_v($d['daily_sale']);?></td>
      <td class="col-coll"><?php echo ds_v($d['rcvd_credit']);?></td>
      <td class="col-coll"><?php echo ds_v($d['rtn_chq']);?></td>
      <td class="col-coll"><?php echo ds_v($d['rtn_chgs']);?></td>
      <td class="col-coll"><?php echo ds_v($d['sent_back_chq']);?></td>
      <td class="col-coll" style="font-weight:700;"><?php echo ds_v($d['total_coll']);?></td>
      <td class="col-dep"><?php echo ds_v($d['bank_deposit']);?></td>
      <td class="col-dep"><?php echo ds_v($d['bo_handover']);?></td>
      <td class="col-sht"><?php echo ds_se($d['excess_short']);?></td>
      <td class="col-tr"><?php echo ds_cross($d);?></td>
      <td class="col-new"><?php echo ds_se($d['final_se']);?></td>
      <td class="col-pay"><?php echo $d['emply_chg']>0?'<span class="ds-charge">'.number_format($d['emply_chg'],2).'</span>':'<span class="ds-dash">—</span>';?></td>
      <td class="col-pay"><?php echo $d['chg_to_com']>0?'<span class="ds-absorb">'.number_format($d['chg_to_com'],2).'</span>':'<span class="ds-dash">—</span>';?></td>
    </tr>
    <?php endforeach; ?>
    <?php endforeach; ?>
    <?php endif; ?>

    </tbody>
    <tfoot>
      <tr>
        <td class="tl stk" colspan="2">TOTAL<?php echo $view_mode==='repcode_wise'?' — '.count($rows).' records':' — '.count($grouped??[]).' reps';?></td>
        <td><?php echo ds_t($grand['daily_sale']);?></td>
        <td><?php echo ds_t($grand['rcvd_credit']);?></td>
        <td><?php echo ds_t($grand['rtn_chq']);?></td>
        <td><?php echo ds_t($grand['rtn_chgs']);?></td>
        <td><?php echo ds_t($grand['sent_back_chq']);?></td>
        <td><?php echo ds_t($grand['total_coll']);?></td>
        <td><?php echo ds_t($grand['bank_deposit']);?></td>
        <td><?php echo ds_t($grand['bo_handover']);?></td>
        <td><?php echo ds_se($grand['excess_short']);?></td>
        <td>
          <?php if($grand['cross_charge_out']>0||$grand['cross_charge_in']>0):?>
            <?php if($grand['cross_charge_out']>0):?><span style="color:#f87171;font-family:var(--mn);font-size:10px;">▼<?php echo number_format($grand['cross_charge_out'],2);?></span><?php endif;?>
            <?php if($grand['cross_charge_in']>0):?> <span style="color:#34d399;font-family:var(--mn);font-size:10px;">▲<?php echo number_format($grand['cross_charge_in'],2);?></span><?php endif;?>
          <?php else:?>—<?php endif;?>
        </td>
        <td><?php echo ds_se($grand['final_se']);?></td>
        <td><?php echo ds_t($grand['emply_chg']);?></td>
        <td><?php echo ds_t($grand['chg_to_com']);?></td>
      </tr>
    </tfoot>
  </table>
  </div>
</div>
<?php endif; ?>

</div><!-- /pg -->

<!-- ═══════════════════════════════════════════════════════
     MODAL: SAVE REPORT (posts to save_cash_shortage_report.php)
════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="saveModal">
  <div class="modal-box">
    <div class="modal-head">
      <h3><i class="fa-solid fa-floppy-disk" style="color:#7c3aed;"></i> Save Report Snapshot</h3>
      <button class="modal-close" onclick="closeSaveModal()">✕</button>
    </div>
    <div class="modal-body">
      <div class="sm-info-grid">
        <div class="sm-info-item"><span class="lbl"><i class="fa-solid fa-calendar"></i> Date Range</span><span class="val" id="sm_daterange">—</span></div>
        <div class="sm-info-item"><span class="lbl"><i class="fa-solid fa-layer-group"></i> View Mode</span><span class="val" id="sm_viewmode">—</span></div>
        <div class="sm-info-item"><span class="lbl"><i class="fa-solid fa-id-badge"></i> Rep Filter</span><span class="val" id="sm_repfilter">—</span></div>
        <div class="sm-info-item"><span class="lbl"><i class="fa-solid fa-database"></i> Records</span><span class="val" id="sm_records">—</span></div>
      </div>
      <div class="sm-field">
        <label><i class="fa-solid fa-tag"></i> Report Name <span style="color:#dc2626;">*</span></label>
        <input type="text" id="sm_name" placeholder="e.g. Daily Shortage – April 2025 – All Reps" maxlength="200">
      </div>
      <div class="sm-field">
        <label><i class="fa-solid fa-note-sticky"></i> Notes <span style="color:var(--txs);font-weight:400;">(optional)</span></label>
        <input type="text" id="sm_notes" placeholder="Any remarks about this snapshot…" maxlength="500">
      </div>
    </div>
    <div class="modal-foot">
      <button onclick="closeSaveModal()" class="btn-rst">Cancel</button>
      <button onclick="doSaveReport()" class="btn-save" id="sm_saveBtn">
        <i class="fa-solid fa-floppy-disk"></i> Save to Database
      </button>
    </div>
  </div>
</div>

<!-- Spinner -->
<div class="spin-overlay" id="spinOverlay">
  <div class="spin-box"><i class="fa-solid fa-spinner fa-spin" style="font-size:18px;color:#7c3aed;"></i> Saving report…</div>
</div>

<div id="dsToast"></div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
<script>
/* ── PHP → JS ── */
var DS_ROWS  = <?php echo json_encode($rows ?? []); ?>;
var DS_GRAND = <?php echo json_encode($grand); ?>;
var DS_META  = {
    date_from   : '<?php echo addslashes($date_from); ?>',
    date_to     : '<?php echo addslashes($date_to); ?>',
    view_mode   : '<?php echo addslashes($view_mode); ?>',
    sr_code     : '<?php echo addslashes($f_sr); ?>',
    record_count: <?php echo count($rows ?? []); ?>,
    submitted   : <?php echo $submitted ? 'true' : 'false'; ?>
};

/* ── View mode auto-submit ── */
document.querySelectorAll('input[name="view_mode"]').forEach(function(el){
    el.addEventListener('change', function(){ document.getElementById('dsf').submit(); });
});
document.getElementById('dsf')?.addEventListener('submit', function(){
    var b=document.getElementById('gBtn');
    b.disabled=true; b.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Generating…';
});

/* ── Expand/Collapse rep groups ── */
function toggleGroup(srCode){
    var matches=[];
    document.querySelectorAll('tr.det-row').forEach(function(r){
        if(r.classList.contains('det-'+srCode)) matches.push(r);
    });
    var ico=document.getElementById('expico-'+srCode);
    var hidden=matches.length===0||matches[0].style.display==='none';
    matches.forEach(function(r){ r.style.display=hidden?'':'none'; });
    if(ico) ico.className=hidden?'fa-solid fa-chevron-up':'fa-solid fa-chevron-down';
}

/* ── Sync scrollbars ── */
(function(){
    var top=document.getElementById('topScroll'), main=document.getElementById('mainScroll');
    if(!top||!main) return;
    var inner=document.getElementById('topScrollInner');
    function setW(){ var tbl=main.querySelector('table.dst'); if(tbl) inner.style.width=tbl.scrollWidth+'px'; }
    setW(); window.addEventListener('resize',setW);
    var syncing=false;
    top.addEventListener('scroll',function(){ if(syncing)return; syncing=true; main.scrollLeft=top.scrollLeft; syncing=false; });
    main.addEventListener('scroll',function(){ if(syncing)return; syncing=true; top.scrollLeft=main.scrollLeft; syncing=false; });
})();

/* ── Excel Export ── */
function exportToExcel(){
    var tbl=document.getElementById('dstMain');
    if(!tbl){ showDsToast('No data.','err'); return; }
    var hdrs=['Rep Code','Date','Daily Cash Sale','Rcvd Credit','RTN Chqs','RTN Chgs','Sent Back Chq','Total Collections','Bank Deposit','BO Hand Over','Excess/Short','Cross Charge OUT','Cross Charge IN','Final (Ex/Short)','Emply Chg','Chg to Com'];
    var data=[];
    tbl.querySelectorAll('tbody tr').forEach(function(tr){
        if(tr.classList.contains('grp-row')||tr.classList.contains('TH')) return;
        var cells=tr.querySelectorAll('td');
        if(cells.length<14) return;
        var row=[]; cells.forEach(function(td){ row.push(td.innerText.trim().replace(/[▼▲✓]/g,'').trim()); });
        data.push(row);
    });
    if(!data.length){ showDsToast('No row data to export.','err'); return; }
    var ws=XLSX.utils.aoa_to_sheet([hdrs].concat(data));
    var wb=XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb,ws,'Daily Cash Shortage');
    XLSX.writeFile(wb,'Daily_Cash_Shortage_<?php echo date("d-M-Y",strtotime($date_from));?>_to_<?php echo date("d-M-Y",strtotime($date_to));?>.xlsx');
    showDsToast('Excel exported!','ok');
}

/* ══════════════════════════════════════
   SAVE REPORT → save_cash_shortage_report.php
══════════════════════════════════════ */
function openSaveModal(){
    if(!DS_META.submitted || DS_ROWS.length===0){
        showDsToast('No data loaded to save.','err'); return;
    }
    var df=DS_META.date_from, dt=DS_META.date_to;
    document.getElementById('sm_daterange').textContent = fmtDatePHP(df)+' – '+fmtDatePHP(dt);
    document.getElementById('sm_viewmode').textContent  = DS_META.view_mode==='rep_wise'?'Rep-Wise (Grouped)':'Rep Code Wise';
    document.getElementById('sm_repfilter').textContent = DS_META.sr_code||'All Reps';
    document.getElementById('sm_records').textContent   = DS_ROWS.length+' records';
    var sr=DS_META.sr_code?' · '+DS_META.sr_code:' · All Reps';
    var vm=DS_META.view_mode==='rep_wise'?'Rep-Wise':'Rep Code';
    document.getElementById('sm_name').value  = 'Cash Shortage '+fmtDatePHP(df)+' – '+fmtDatePHP(dt)+sr+' · '+vm;
    document.getElementById('sm_notes').value = '';
    document.getElementById('saveModal').classList.add('open');
    setTimeout(function(){ document.getElementById('sm_name').focus(); document.getElementById('sm_name').select(); },200);
}
function closeSaveModal(){ document.getElementById('saveModal').classList.remove('open'); }

function doSaveReport(){
    var name  = document.getElementById('sm_name').value.trim();
    var notes = document.getElementById('sm_notes').value.trim();
    if(!name){ showDsToast('Please enter a report name.','err'); document.getElementById('sm_name').focus(); return; }

    var payload = { rows: DS_ROWS, grand: DS_GRAND };
    document.getElementById('spinOverlay').classList.add('open');
    document.getElementById('saveModal').classList.remove('open');

    var fd=new FormData();
    fd.append('action',      'save_report');
    fd.append('report_name', name);
    fd.append('date_from',   DS_META.date_from);
    fd.append('date_to',     DS_META.date_to);
    fd.append('view_mode',   DS_META.view_mode);
    fd.append('sr_code',     DS_META.sr_code);
    fd.append('notes',       notes);
    fd.append('report_data', JSON.stringify(payload));

    fetch('save_cash_shortage_report.php', { method:'POST', body:fd })
    .then(function(r){ return r.json(); })
    .then(function(res){
        document.getElementById('spinOverlay').classList.remove('open');
        if(res.success){
            showDsToast('✓ Saved! ID #'+res.id+' · View in Saved Reports','ok');
        } else {
            showDsToast('Error: '+res.message,'err');
        }
    })
    .catch(function(e){
        document.getElementById('spinOverlay').classList.remove('open');
        showDsToast('Network error: '+e.message,'err');
    });
}

/* ── Helpers ── */
function fmtDatePHP(d){
    if(!d||d==='0000-00-00') return '—';
    var p=d.split('-'); if(p.length<3) return d;
    var m=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return p[2]+' '+m[parseInt(p[1],10)-1]+' '+p[0];
}
function escH(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function showDsToast(msg,type){
    var t=document.getElementById('dsToast');
    t.className=type==='ok'?'toast-ok':(type==='info'?'toast-info':'toast-err');
    t.textContent=msg; t.style.display='block'; t.style.opacity='1';
    clearTimeout(t._t);
    t._t=setTimeout(function(){ t.style.opacity='0'; setTimeout(function(){ t.style.display='none'; },300); },2800);
}
document.getElementById('saveModal').addEventListener('click',function(e){ if(e.target===this) closeSaveModal(); });
</script>
<?php include 'footer.php'; ?>
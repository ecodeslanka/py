<?php
/**
 * LeverEDGE — Damage/Shortage vs Invoice Sales TUR Reconciliation
 * Report 1 : Damage-Shortage Proposal Status Report  (header = row 2)
 * Report 2 : Invoice Wise Sales                       (header = row 19)
 *
 * MATCH KEY : RETAILER CODE (= Outlet Code) + PRODUCT CODE + TUR
 * TALLY     : R1 TOTAL TUR VALUE  vs  R2 Damage-Expiry Shortage Value
 * AI        : Google Gemini 1.5 Flash
 */

include_once 'config.php';   // $conn (mysqli)

/* ── Gemini API key from ai_settings ── */
$GEMINI_KEY = '';
if (!empty($conn)) {
    $rs = mysqli_query($conn, "SELECT `value` FROM ai_settings WHERE `key`='gemini_api_key' LIMIT 1");
    if ($rs && $row_ai = mysqli_fetch_assoc($rs)) $GEMINI_KEY = trim($row_ai['value']);
}
$GEMINI_KEY_SET = ($GEMINI_KEY !== '');

$MAX_SIZE = 10 * 1024 * 1024;

/* ── Pure-PHP XLSX reader ── */
function parseXlsxBasic(string $path): array {
    $rows=[]; $zip=new ZipArchive();
    if ($zip->open($path)!==true) return $rows;
    $ss=[]; $xml=$zip->getFromName('xl/sharedStrings.xml');
    if ($xml) { $d=new DOMDocument(); @$d->loadXML($xml);
        foreach($d->getElementsByTagName('si') as $si) $ss[]=$si->textContent; }
    $s1=$zip->getFromName('xl/worksheets/sheet1.xml');
    if (!$s1) { $zip->close(); return $rows; }
    $d=new DOMDocument(); @$d->loadXML($s1);
    foreach ($d->getElementsByTagName('row') as $rn) {
        $row=[];
        foreach ($rn->getElementsByTagName('c') as $c) {
            $t=$c->getAttribute('t'); $v=$c->getElementsByTagName('v')->item(0);
            $val=$v?$v->nodeValue:''; if($t==='s') $val=$ss[(int)$val]??'';
            $row[]=$val;
        }
        $rows[]=$row;
    }
    $zip->close(); return $rows;
}

function rowsToAssoc(array $rows, int $hi): array {
    if (!isset($rows[$hi])) return [];
    $hdr=array_map('strval',$rows[$hi]); $out=[];
    for ($i=$hi+1;$i<count($rows);$i++) {
        $r=$rows[$i];
        if (empty(array_filter($r,fn($v)=>$v!==''&&$v!==null))) continue;
        while (count($r)<count($hdr)) $r[]='';
        $out[]=array_combine($hdr,array_slice($r,0,count($hdr)));
    }
    return $out;
}

function cc(string $v): string {
    $v=strtoupper(trim($v)); return preg_replace('/\.0+$/','',$v);
}
function ct(string $v): string { return number_format((float)$v,2,'.',''); }
function fmtN(float $n,int $d=2): string { return number_format($n,$d); }

/* ═══ PROCESS ═══ */
$errors=[]; $results=null;

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['process'])) {
    foreach(['report1','report2'] as $f) {
        if (empty($_FILES[$f]['tmp_name'])||$_FILES[$f]['error']!==UPLOAD_ERR_OK) $errors[]="Missing file: $f";
        elseif ($_FILES[$f]['size']>$MAX_SIZE) $errors[]="File $f exceeds 10 MB";
        elseif (!str_ends_with(strtolower($_FILES[$f]['name']),'.xlsx')) $errors[]="$f must be .xlsx";
    }
    if (empty($errors)) {
        $t1=sys_get_temp_dir().'/r1_'.uniqid().'.xlsx';
        $t2=sys_get_temp_dir().'/r2_'.uniqid().'.xlsx';
        move_uploaded_file($_FILES['report1']['tmp_name'],$t1);
        move_uploaded_file($_FILES['report2']['tmp_name'],$t2);
        try {
            $dmg  = rowsToAssoc(parseXlsxBasic($t1),1);   // R1 header row 2
            $sale = rowsToAssoc(parseXlsxBasic($t2),18);  // R2 header row 19

            /* ── Aggregate R1: key = RC|PC|TUR (no batch) ── */
            $agg1=[]; $tot1=0.0;
            foreach ($dmg as $row) {
                $rc=$row['RETAILER CODE']??''; $pc=$row['PRODUCT CODE']??'';
                $tur=$row['TUR']??'0';
                if ($rc===''&&$pc==='') continue;
                $tv=(float)($row['TOTAL TUR VALUE']??0);
                $key=cc($rc).'|'.cc($pc).'|'.ct($tur);
                if(!isset($agg1[$key])) $agg1[$key]=['r1_tur'=>0.0,'qty'=>0.0,
                    'RC'=>cc($rc),'PC'=>cc($pc),'TUR'=>(float)$tur,
                    'PN'=>$row['PRODUCT NAME']??'','RN'=>$row['RETAILER NAME']??'',
                    'RT'=>[],'AR'=>[]];
                $agg1[$key]['r1_tur']+=$tv;
                $agg1[$key]['qty']+=(float)($row['QTY/FREE QTY']??0);
                $rt=$row['RETURN TYPE']??''; $ar=$row['APP REASON']??'';
                if($rt&&!in_array($rt,$agg1[$key]['RT'])) $agg1[$key]['RT'][]=$rt;
                if($ar&&!in_array($ar,$agg1[$key]['AR'])) $agg1[$key]['AR'][]=$ar;
                $tot1+=$tv;
            }

            /* ── Aggregate R2: key = OC|PC|TUR (no batch) ── */
            $agg2=[]; $tot2=0.0;
            foreach ($sale as $row) {
                $oc=$row['Outlet Code']??'';  $pc=$row['Product code']??'';
                $tur=$row['TUR']??'0';
                if ($oc===''&&$pc==='') continue;
                $dsv=(float)($row['Damage-Expiry Shortage Value']??0);
                if ($dsv==0) continue;
                $key=cc($oc).'|'.cc($pc).'|'.ct($tur);
                if(!isset($agg2[$key])) $agg2[$key]=['r2_dmg'=>0.0,'qty'=>0.0,
                    'OC'=>cc($oc),'PC'=>cc($pc),'TUR'=>(float)$tur,
                    'PN'=>$row['Product Description']??'','ON'=>$row['Outlet Name']??''];
                $agg2[$key]['r2_dmg']+=$dsv;
                $agg2[$key]['qty']+=(float)($row['Damage-Expiry Shortage Qty']??0);
                $tot2+=$dsv;
            }

            /* ── Reconcile ── */
            $matched=[]; $onlyR1=[]; $onlyR2=[];
            foreach ($agg1 as $key=>$d) {
                if (isset($agg2[$key])) {
                    $s=$agg2[$key];
                    $matched[]=['key'=>$key,'RC'=>$d['RC'],'PC'=>$d['PC'],
                        'TUR'=>$d['TUR'],'PN'=>$d['PN']?:$s['PN'],'RN'=>$d['RN']?:$s['ON'],
                        'r1_tur'=>$d['r1_tur'],'r2_dmg'=>$s['r2_dmg'],'diff'=>$d['r1_tur']-$s['r2_dmg'],
                        'r1_qty'=>$d['qty'],'r2_qty'=>$s['qty'],'RT'=>$d['RT'],'AR'=>$d['AR']];
                } else {
                    $onlyR1[]=['key'=>$key,'RC'=>$d['RC'],'PC'=>$d['PC'],
                        'TUR'=>$d['TUR'],'PN'=>$d['PN'],'RN'=>$d['RN'],
                        'r1_tur'=>$d['r1_tur'],'qty'=>$d['qty'],'RT'=>$d['RT'],'AR'=>$d['AR']];
                }
            }
            foreach ($agg2 as $key=>$s) {
                if (!isset($agg1[$key]))
                    $onlyR2[]=['key'=>$key,'RC'=>$s['OC'],'PC'=>$s['PC'],
                        'TUR'=>$s['TUR'],'PN'=>$s['PN'],'RN'=>$s['ON'],
                        'r2_dmg'=>$s['r2_dmg'],'qty'=>$s['qty']];
            }

            usort($matched, fn($a,$b)=>abs($b['diff'])<=>abs($a['diff']));
            usort($onlyR1,  fn($a,$b)=>$b['r1_tur']<=>$a['r1_tur']);
            usort($onlyR2,  fn($a,$b)=>$b['r2_dmg']<=>$a['r2_dmg']);

            $results=compact('tot1','tot2','matched','onlyR1','onlyR2');

        } catch(Throwable $e) { $errors[]='Error: '.$e->getMessage(); }
        @unlink($t1); @unlink($t2);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>TUR Reconciliation — LeverEDGE</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
:root{
  --ink:#0d0f14;--ink2:#3a3f4b;--ink3:#6b7280;
  --bg:#f0f2f5;--sur:#fff;--sur2:#f7f8fa;--bdr:#e3e6ec;
  --gold:#e8a020;--gold-l:#fdf3e0;--gold-b:#f5c96e;
  --blue:#1849d6;--blue-l:#eef2fd;--blue-b:#93adee;
  --green:#0d7a55;--green-l:#e6f7f2;--green-b:#6ecdb3;
  --red:#c42b2b;--red-l:#fdf0f0;--red-b:#f0a0a0;
  --purple:#6d28d9;--purple-l:#f3effe;
  --gemini-a:#1a73e8;--gemini-b:#4285f4;--gemini-c:#34a853;
  --r:10px;--r2:16px;--sh:0 2px 8px rgba(0,0,0,.07);
  --fn:'DM Sans',sans-serif;--ff:'Syne',sans-serif;--fm:'DM Mono',monospace;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--fn);background:var(--bg);color:var(--ink);font-size:14px;min-height:100vh;}
.wrap{max-width:1400px;margin:0 auto;padding:26px 18px 80px;}
/* HEADER */
.hdr{background:linear-gradient(135deg,#0d0f14 0%,#1a2540 50%,#0d1a30 100%);
  border-radius:var(--r2);padding:28px 34px;margin-bottom:24px;
  display:flex;align-items:center;justify-content:space-between;gap:16px;
  position:relative;overflow:hidden;}
.hdr::before{content:'';position:absolute;inset:0;
  background:radial-gradient(circle at 80% 50%,rgba(232,160,32,.14) 0%,transparent 60%);pointer-events:none;}
.hdr h1{font-family:var(--ff);font-size:26px;font-weight:800;color:#fff;letter-spacing:-.02em;}
.hdr h1 span{color:var(--gold);}
.hdr p{color:rgba(255,255,255,.5);font-size:12px;margin-top:4px;}
.hdr-badge{background:rgba(232,160,32,.15);border:1px solid rgba(232,160,32,.3);
  color:var(--gold-b);border-radius:20px;padding:5px 14px;font-size:11px;font-weight:700;font-family:var(--ff);white-space:nowrap;}
/* UPLOAD */
.ucard{background:var(--sur);border:1px solid var(--bdr);border-radius:var(--r2);box-shadow:var(--sh);padding:26px 30px;margin-bottom:22px;}
.uc-ttl{font-family:var(--ff);font-size:16px;font-weight:700;margin-bottom:18px;display:flex;align-items:center;gap:9px;}
.uc-ttl .ic{width:32px;height:32px;border-radius:8px;background:var(--gold-l);color:var(--gold);display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;}
.ugrid{display:grid;grid-template-columns:1fr 1fr;gap:18px;}
@media(max-width:680px){.ugrid{grid-template-columns:1fr;}}
.dz{border:2px dashed var(--bdr);border-radius:var(--r2);padding:26px 18px;text-align:center;cursor:pointer;transition:all .2s;position:relative;background:var(--sur2);}
.dz:hover,.dz.over{border-color:var(--blue);background:var(--blue-l);}
.dz input{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
.dz-ic{font-size:30px;color:var(--ink3);margin-bottom:8px;}
.dz-lbl{font-size:13px;font-weight:700;color:var(--ink2);margin-bottom:3px;}
.dz-sub{font-size:11px;color:var(--ink3);}
.dz-tag{display:inline-block;background:var(--blue-l);color:var(--blue);border-radius:4px;font-family:var(--fm);font-size:10px;padding:2px 7px;margin-top:5px;font-weight:500;}
.dz-fn{font-size:11px;font-weight:600;color:var(--green);margin-top:7px;display:none;align-items:center;gap:4px;}
.btn-go{display:inline-flex;align-items:center;gap:8px;margin-top:18px;padding:11px 28px;
  background:linear-gradient(135deg,var(--blue),#1238b8);color:#fff;border:none;border-radius:var(--r);
  font-size:14px;font-weight:700;cursor:pointer;font-family:var(--fn);
  box-shadow:0 4px 14px rgba(24,73,214,.28);transition:all .15s;}
.btn-go:hover{transform:translateY(-1px);}
/* ALERT */
.alert{border-radius:var(--r);padding:11px 15px;margin-bottom:14px;font-size:13px;font-weight:600;display:flex;align-items:flex-start;gap:9px;border:1px solid;}
.alert.err{background:var(--red-l);color:var(--red);border-color:var(--red-b);}
/* SUMMARY */
.sstrip{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px;}
@media(max-width:1080px){.sstrip{grid-template-columns:repeat(3,1fr);}}
@media(max-width:560px){.sstrip{grid-template-columns:1fr 1fr;}}
.sc{background:var(--sur);border:1px solid var(--bdr);border-radius:var(--r2);padding:18px 20px;box-shadow:var(--sh);position:relative;overflow:hidden;}
.sc::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:3px 3px 0 0;}
.sc.gold::before{background:linear-gradient(90deg,var(--gold),var(--gold-b));}
.sc.blue::before{background:linear-gradient(90deg,var(--blue),var(--blue-b));}
.sc.green::before{background:linear-gradient(90deg,var(--green),var(--green-b));}
.sc.red::before{background:linear-gradient(90deg,var(--red),var(--red-b));}
.sc.purple::before{background:linear-gradient(90deg,var(--purple),#a855f7);}
.sc-lbl{font-size:10px;font-weight:700;color:var(--ink3);text-transform:uppercase;letter-spacing:.07em;margin-bottom:5px;}
.sc-val{font-family:var(--ff);font-size:20px;font-weight:800;letter-spacing:-.02em;}
.sc.gold .sc-val{color:var(--gold);} .sc.blue .sc-val{color:var(--blue);}
.sc.green .sc-val{color:var(--green);} .sc.red .sc-val{color:var(--red);} .sc.purple .sc-val{color:var(--purple);}
.sc-sub{font-size:11px;color:var(--ink3);margin-top:3px;}
/* TABS */
.tabs{display:flex;gap:7px;margin-bottom:14px;flex-wrap:wrap;}
.tab{padding:8px 17px;border-radius:20px;font-size:12px;font-weight:700;cursor:pointer;border:1.5px solid var(--bdr);background:var(--sur);color:var(--ink2);transition:all .15s;font-family:var(--ff);}
.tab.on{background:var(--blue);color:#fff;border-color:var(--blue);}
.tab .cn{margin-left:5px;background:rgba(255,255,255,.22);border-radius:10px;padding:1px 7px;font-size:10px;}
.tab:not(.on) .cn{background:var(--bdr);color:var(--ink3);}
/* TABLE */
.twrap{background:var(--sur);border:1px solid var(--bdr);border-radius:var(--r2);box-shadow:var(--sh);overflow:hidden;margin-bottom:20px;}
.thead2{padding:14px 18px;border-bottom:1px solid var(--bdr);display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;}
.thead2 h3{font-family:var(--ff);font-size:14px;font-weight:700;}
.srch{padding:7px 11px;border:1px solid var(--bdr);border-radius:7px;font-size:12px;font-family:var(--fn);color:var(--ink);outline:none;width:210px;}
.srch:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(24,73,214,.1);}
.tscroll{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:12px;}
thead th{background:#f5f6f8;padding:9px 13px;text-align:left;font-size:10px;font-weight:800;color:var(--ink3);text-transform:uppercase;letter-spacing:.07em;white-space:nowrap;border-bottom:1px solid var(--bdr);position:sticky;top:0;z-index:2;}
thead th.r{text-align:right;}
tbody tr{border-bottom:1px solid #f0f1f3;transition:background .1s;}
tbody tr:hover{background:#f8f9fc;}
td{padding:8px 13px;vertical-align:middle;}
td.mono{font-family:var(--fm);font-size:11px;} td.num{text-align:right;font-family:var(--fm);font-weight:500;}
tfoot td{background:#f0f2f5;font-weight:800;font-family:var(--fm);font-size:12px;padding:9px 13px;border-top:2px solid var(--bdr);}
tfoot td.num{text-align:right;}
.dp{color:var(--red);font-weight:700;} .dn{color:var(--green);font-weight:700;} .dz2{color:var(--ink3);}
.pill{display:inline-block;font-size:9px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:4px;padding:1px 6px;color:var(--ink2);font-weight:600;white-space:nowrap;margin:1px;}
/* EXPAND */
tr.xrow td{padding:0;}
.xinr{padding:13px 18px;background:#f8f9fc;border-top:1px dashed var(--bdr);display:none;}
.xinr.on{display:block;}
.xgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px;margin-bottom:8px;}
.xi .xl{font-size:9px;font-weight:700;color:var(--ink3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px;}
.xi .xv{font-size:12px;font-weight:600;color:var(--ink);}
.xtog{cursor:pointer;color:var(--blue);font-size:10px;font-weight:700;user-select:none;}
.xtog:hover{text-decoration:underline;}
/* AI PANEL — Gemini */
.aipanel{background:linear-gradient(135deg,#0d0f14,#0d1a30);border-radius:var(--r2);padding:26px 28px;margin-bottom:22px;position:relative;overflow:hidden;}
.aipanel::before{content:'';position:absolute;inset:0;
  background:radial-gradient(circle at 20% 50%,rgba(26,115,232,.2) 0%,transparent 55%),
             radial-gradient(circle at 80% 50%,rgba(52,168,83,.12) 0%,transparent 55%);
  pointer-events:none;}
.aihdr{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px;flex-wrap:wrap;}
.aittl{font-family:var(--ff);font-size:17px;font-weight:800;color:#fff;display:flex;align-items:center;gap:9px;}
.aiic{width:34px;height:34px;border-radius:9px;background:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 2px 8px rgba(0,0,0,.25);}
.aiic svg{width:20px;height:20px;}
.btn-ai{display:inline-flex;align-items:center;gap:7px;padding:9px 20px;
  background:linear-gradient(135deg,var(--gemini-a),var(--gemini-c));
  color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;
  cursor:pointer;font-family:var(--fn);
  box-shadow:0 4px 14px rgba(26,115,232,.35);transition:all .15s;}
.btn-ai:hover{opacity:.9;transform:translateY(-1px);}
.btn-ai:disabled{opacity:.45;cursor:not-allowed;transform:none;}
#aiOut{display:none;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.11);border-radius:var(--r);padding:16px 18px;color:rgba(255,255,255,.87);font-size:13px;line-height:1.8;white-space:pre-wrap;font-family:var(--fn);margin-top:4px;}
#aiOut strong{color:#93c5fd;}
.aild{display:none;align-items:center;gap:9px;color:rgba(255,255,255,.55);font-size:13px;padding:14px 0;}
.dot{width:7px;height:7px;border-radius:50%;animation:pu 1.4s infinite ease-in-out;}
.dot:nth-child(1){background:var(--gemini-a);}
.dot:nth-child(2){background:#9b72cb;animation-delay:.2s;}
.dot:nth-child(3){background:var(--gemini-c);animation-delay:.4s;}
@keyframes pu{0%,80%,100%{transform:scale(.6);opacity:.4;}40%{transform:scale(1);opacity:1;}}
.spanel{display:none;} .spanel.on{display:block;}
.empty{text-align:center;padding:28px;color:var(--ink3);font-style:italic;font-size:13px;}
code{background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:10px;font-family:var(--fm);}
</style>
</head>
<body>
<div class="wrap">

<div class="hdr">
  <div>
    <h1>TUR <span>Reconciliation</span></h1>
    <p>Damage/Shortage Proposal &nbsp;↔&nbsp; Invoice Wise Sales &nbsp;·&nbsp; Match key: Retailer/Outlet Code + Product Code + TUR</p>
  </div>
  <div class="hdr-badge"><i class="fa-solid fa-chart-bar"></i> &nbsp;LeverEDGE Analytics</div>
</div>

<?php foreach($errors as $e):?>
<div class="alert err"><i class="fa-solid fa-circle-exclamation"></i><?=htmlspecialchars($e)?></div>
<?php endforeach;?>

<form method="POST" enctype="multipart/form-data" id="uf">
<div class="ucard">
  <div class="uc-ttl"><div class="ic"><i class="fa-solid fa-file-arrow-up"></i></div>Upload Reports</div>
  <div class="ugrid">
    <div>
      <div style="font-size:11px;font-weight:800;color:var(--ink3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:7px;">
        <i class="fa-solid fa-file-excel" style="color:#22a55a;margin-right:4px;"></i>Report 1 — Damage / Shortage Proposal
      </div>
      <div class="dz" id="dz1">
        <input type="file" name="report1" accept=".xlsx" onchange="onFile(this,'dz1','fn1')">
        <div class="dz-ic"><i class="fa-solid fa-cloud-arrow-up"></i></div>
        <div class="dz-lbl">Drop or click to upload</div>
        <div class="dz-sub">Damage-Shortage Proposal Status Report</div>
        <div class="dz-tag">XLSX · Header row 2</div>
        <div class="dz-fn" id="fn1"><i class="fa-solid fa-circle-check"></i><span></span></div>
      </div>
      <div style="font-size:11px;color:var(--ink3);margin-top:5px;">
        Match: <code>RETAILER CODE · PRODUCT CODE · TUR</code> &nbsp;|&nbsp; Sum: <code>TOTAL TUR VALUE</code>
      </div>
    </div>
    <div>
      <div style="font-size:11px;font-weight:800;color:var(--ink3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:7px;">
        <i class="fa-solid fa-file-excel" style="color:#22a55a;margin-right:4px;"></i>Report 2 — Invoice Wise Sales
      </div>
      <div class="dz" id="dz2">
        <input type="file" name="report2" accept=".xlsx" onchange="onFile(this,'dz2','fn2')">
        <div class="dz-ic"><i class="fa-solid fa-cloud-arrow-up"></i></div>
        <div class="dz-lbl">Drop or click to upload</div>
        <div class="dz-sub">Invoice Wise Sales Detail Report</div>
        <div class="dz-tag">XLSX · Header row 19</div>
        <div class="dz-fn" id="fn2"><i class="fa-solid fa-circle-check"></i><span></span></div>
      </div>
      <div style="font-size:11px;color:var(--ink3);margin-top:5px;">
        Match: <code>Outlet Code · Product code · TUR</code> &nbsp;|&nbsp; Sum: <code>Damage-Expiry Shortage Value</code>
      </div>
    </div>
  </div>
  <button type="submit" name="process" class="btn-go" id="gobtn">
    <i class="fa-solid fa-bolt"></i> Process & Reconcile
  </button>
</div>
</form>

<?php if($results): ?>
<?php
  $r=$results;
  $cntM=count($r['matched']); $cntR1=count($r['onlyR1']); $cntR2=count($r['onlyR2']);
  $cntV=count(array_filter($r['matched'],fn($x)=>abs($x['diff'])>0.01));
  $cntE=$cntM-$cntV;
  $mDiff=array_sum(array_column($r['matched'],'diff'));
  $netDiff=$r['tot1']-$r['tot2'];
  $r1tot=array_sum(array_column($r['onlyR1'],'r1_tur'));
  $r2tot=array_sum(array_column($r['onlyR2'],'r2_dmg'));
?>

<div class="sstrip">
  <div class="sc gold">
    <div class="sc-lbl"><i class="fa-solid fa-file-invoice"></i> R1 Total TUR Value</div>
    <div class="sc-val">LKR <?=fmtN($r['tot1'])?></div>
    <div class="sc-sub">Proposal TOTAL TUR VALUE</div>
  </div>
  <div class="sc blue">
    <div class="sc-lbl"><i class="fa-solid fa-receipt"></i> R2 Damage Total</div>
    <div class="sc-val">LKR <?=fmtN($r['tot2'])?></div>
    <div class="sc-sub">Damage-Expiry Shortage Value</div>
  </div>
  <div class="sc <?=abs($netDiff)<0.01?'green':($netDiff>0?'red':'green')?>">
    <div class="sc-lbl"><i class="fa-solid fa-scale-balanced"></i> Net Difference</div>
    <div class="sc-val">LKR <?=fmtN(abs($netDiff))?></div>
    <div class="sc-sub"><?=abs($netDiff)<0.01?'Balanced ✓':($netDiff>0?'R1 exceeds R2':'R2 exceeds R1')?></div>
  </div>
  <div class="sc <?=$cntR1>0?'red':'green'?>">
    <div class="sc-lbl"><i class="fa-solid fa-circle-xmark"></i> Only in R1</div>
    <div class="sc-val"><?=$cntR1?> keys</div>
    <div class="sc-sub">LKR <?=fmtN($r1tot)?></div>
  </div>
  <div class="sc <?=$cntR2>0?'purple':'green'?>">
    <div class="sc-lbl"><i class="fa-solid fa-circle-question"></i> Only in R2</div>
    <div class="sc-val"><?=$cntR2?> keys</div>
    <div class="sc-sub">LKR <?=fmtN($r2tot)?></div>
  </div>
</div>

<!-- AI PANEL — Gemini -->
<div class="aipanel">
  <div class="aihdr">
    <div class="aittl">
      <!-- Gemini star logo -->
      <div class="aiic">
        <svg viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M14 2C14 2 14.5 9 17.5 11.5C20.5 14 26 14 26 14C26 14 20.5 14 17.5 16.5C14.5 19 14 26 14 26C14 26 13.5 19 10.5 16.5C7.5 14 2 14 2 14C2 14 7.5 14 10.5 11.5C13.5 9 14 2 14 2Z" fill="url(#gai)"/>
          <defs>
            <linearGradient id="gai" x1="2" y1="2" x2="26" y2="26" gradientUnits="userSpaceOnUse">
              <stop stop-color="#4285F4"/>
              <stop offset="0.5" stop-color="#9B72CB"/>
              <stop offset="1" stop-color="#34A853"/>
            </linearGradient>
          </defs>
        </svg>
      </div>
      Gemini AI Analysis
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <?php if(!$GEMINI_KEY_SET):?>
      <a href="ai_settings.php" style="font-size:11px;color:#fbbf24;display:flex;align-items:center;gap:6px;text-decoration:none;">
        <i class="fa-solid fa-triangle-exclamation"></i> Set Gemini API key in AI Settings
      </a>
      <?php endif;?>
      <button class="btn-ai" id="aibtn" onclick="runAI()">
        <i class="fa-solid fa-sparkles"></i>
        <?=$GEMINI_KEY_SET?'Analyse with Gemini':'Configure Key First'?>
      </button>
    </div>
  </div>
  <div class="aild" id="aild">
    <div class="dot"></div><div class="dot"></div><div class="dot"></div>
    <span>Gemini is analysing reconciliation data…</span>
  </div>
  <div id="aiOut"></div>
</div>

<div class="tabs">
  <div class="tab on" onclick="showTab('matched',this)">
    <i class="fa-solid fa-arrows-left-right"></i> Matched <span class="cn"><?=$cntM?></span>
  </div>
  <div class="tab" onclick="showTab('r1',this)">
    <i class="fa-solid fa-file-circle-exclamation"></i> Only in R1 <span class="cn"><?=$cntR1?></span>
  </div>
  <div class="tab" onclick="showTab('r2',this)">
    <i class="fa-solid fa-file-circle-question"></i> Only in R2 <span class="cn"><?=$cntR2?></span>
  </div>
</div>

<!-- MATCHED TABLE -->
<div class="spanel on" id="pan-matched">
<div class="twrap">
  <div class="thead2">
    <h3><i class="fa-solid fa-arrows-left-right" style="color:var(--blue);margin-right:6px;"></i>
      Matched — <?=$cntE?> exact &nbsp;·&nbsp; <?=$cntV?> with variance</h3>
    <input class="srch" placeholder="Search retailer / product / batch…" oninput="ft('tm',this.value)">
  </div>
  <div class="tscroll">
  <table id="tm">
    <thead><tr>
      <th></th>
      <th>Retailer Code</th><th>Product Code</th><th class="r">TUR (LKR)</th>
      <th>Product Name</th><th>Retailer / Outlet</th>
      <th class="r">R1 Total TUR Val</th>
      <th class="r">R2 Damage Val</th>
      <th class="r">Difference</th>
      <th>Return Type</th>
    </tr></thead>
    <tbody>
      <?php foreach($r['matched'] as $i=>$row):?>
      <tr>
        <td><span class="xtog" onclick="tx('xm<?=$i?>')">▶</span></td>
        <td class="mono"><?=htmlspecialchars($row['RC'])?></td>
        <td class="mono"><?=htmlspecialchars($row['PC'])?></td>
        <td class="num"><?=fmtN($row['TUR'])?></td>
        <td style="max-width:170px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?=htmlspecialchars($row['PN'])?>"><?=htmlspecialchars($row['PN'])?></td>
        <td style="max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?=htmlspecialchars($row['RN'])?></td>
        <td class="num"><?=fmtN($row['r1_tur'])?></td>
        <td class="num"><?=fmtN($row['r2_dmg'])?></td>
        <td class="num <?=abs($row['diff'])<0.01?'dz2':($row['diff']>0?'dp':'dn')?>">
          <?=$row['diff']>0.005?'+':''?><?=fmtN($row['diff'])?>
        </td>
        <td><?php foreach($row['RT'] as $rt):?><span class="pill"><?=htmlspecialchars($rt)?></span><?php endforeach;?></td>
      </tr>
      <tr class="xrow"><td colspan="10"><div class="xinr" id="xm<?=$i?>">
        <div class="xgrid">
          <div class="xi"><div class="xl">R1 Qty</div><div class="xv"><?=$row['r1_qty']?></div></div>
          <div class="xi"><div class="xl">R2 Qty</div><div class="xv"><?=$row['r2_qty']?></div></div>
          <div class="xi"><div class="xl">Qty Diff</div><div class="xv <?=($row['r1_qty']-$row['r2_qty'])!=0?'dp':''?>"><?=($row['r1_qty']-$row['r2_qty'])?></div></div>
          <div class="xi"><div class="xl">TUR Rate</div><div class="xv">LKR <?=fmtN($row['TUR'])?></div></div>
          <div class="xi"><div class="xl">Variance %</div><div class="xv"><?=$row['r1_tur']>0?fmtN(($row['diff']/$row['r1_tur'])*100,1).'%':'—'?></div></div>
          <div class="xi"><div class="xl">App Reason</div><div class="xv" style="font-size:11px;"><?=htmlspecialchars(implode(', ',$row['AR']))?:'-'?></div></div>
        </div>
        <?php if(abs($row['diff'])>0.01):?>
        <div style="font-size:12px;font-weight:700;color:var(--red);">
          <i class="fa-solid fa-triangle-exclamation"></i>
          Variance LKR <?=fmtN(abs($row['diff']))?> —
          <?=$row['diff']>0?'R1 TUR exceeds R2 damage value (possible over-claim or unrecorded in sales).':'R2 damage value exceeds R1 TUR (possible under-claim or data entry gap).'?>
        </div>
        <?php else:?>
        <div style="font-size:12px;font-weight:700;color:var(--green);"><i class="fa-solid fa-circle-check"></i> Values match exactly.</div>
        <?php endif;?>
      </div></td></tr>
      <?php endforeach;?>
    </tbody>
    <tfoot><tr>
      <td colspan="6" style="font-size:11px;color:var(--ink3);">TOTAL — <?=$cntM?> matched keys</td>
      <td class="num"><?=fmtN(array_sum(array_column($r['matched'],'r1_tur')))?></td>
      <td class="num"><?=fmtN(array_sum(array_column($r['matched'],'r2_dmg')))?></td>
      <td class="num <?=abs($mDiff)<0.01?'dz2':($mDiff>0?'dp':'dn')?>"><?=$mDiff>=0?'+':''?><?=fmtN($mDiff)?></td>
      <td></td>
    </tr></tfoot>
  </table>
  </div>
</div>
</div>

<!-- ONLY R1 TABLE -->
<div class="spanel" id="pan-r1">
<div class="twrap">
  <div class="thead2">
    <h3><i class="fa-solid fa-file-circle-exclamation" style="color:var(--red);margin-right:6px;"></i>
      Only in Report 1 — no matching key in Report 2</h3>
    <input class="srch" placeholder="Search…" oninput="ft('tr1',this.value)">
  </div>
  <div class="tscroll">
  <table id="tr1">
    <thead><tr>
      <th>Retailer Code</th><th>Product Code</th><th class="r">TUR (LKR)</th>
      <th>Product Name</th><th>Retailer Name</th>
      <th class="r">Total TUR Value (LKR)</th><th class="r">Qty</th><th>Return Type</th>
    </tr></thead>
    <tbody>
      <?php foreach($r['onlyR1'] as $row):?>
      <tr>
        <td class="mono"><?=htmlspecialchars($row['RC'])?></td>
        <td class="mono"><?=htmlspecialchars($row['PC'])?></td>
        <td class="num"><?=fmtN($row['TUR'])?></td>
        <td style="max-width:185px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?=htmlspecialchars($row['PN'])?></td>
        <td><?=htmlspecialchars($row['RN'])?></td>
        <td class="num dp"><?=fmtN($row['r1_tur'])?></td>
        <td class="num"><?=$row['qty']?></td>
        <td><?php foreach($row['RT'] as $rt):?><span class="pill"><?=htmlspecialchars($rt)?></span><?php endforeach;?></td>
      </tr>
      <?php endforeach;?>
      <?php if(empty($r['onlyR1'])):?>
      <tr><td colspan="9" class="empty">All Report 1 records matched in Report 2.</td></tr>
      <?php endif;?>
    </tbody>
    <?php if(!empty($r['onlyR1'])):?>
    <tfoot><tr>
      <td colspan="5" style="font-size:11px;color:var(--ink3);">TOTAL — <?=$cntR1?> unmatched keys</td>
      <td class="num dp"><?=fmtN($r1tot)?></td>
      <td colspan="2"></td>
    </tr></tfoot>
    <?php endif;?>
  </table>
  </div>
</div>
</div>

<!-- ONLY R2 TABLE -->
<div class="spanel" id="pan-r2">
<div class="twrap">
  <div class="thead2">
    <h3><i class="fa-solid fa-file-circle-question" style="color:var(--purple);margin-right:6px;"></i>
      Only in Report 2 — no matching key in Report 1</h3>
    <input class="srch" placeholder="Search…" oninput="ft('tr2',this.value)">
  </div>
  <div class="tscroll">
  <table id="tr2">
    <thead><tr>
      <th>Outlet Code</th><th>Product Code</th><th class="r">TUR (LKR)</th>
      <th>Product Name</th><th>Outlet Name</th>
      <th class="r">Damage Value (LKR)</th><th class="r">Qty</th>
    </tr></thead>
    <tbody>
      <?php foreach($r['onlyR2'] as $row):?>
      <tr>
        <td class="mono"><?=htmlspecialchars($row['RC'])?></td>
        <td class="mono"><?=htmlspecialchars($row['PC'])?></td>
        <td class="num"><?=fmtN($row['TUR'])?></td>
        <td style="max-width:185px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?=htmlspecialchars($row['PN'])?></td>
        <td><?=htmlspecialchars($row['RN'])?></td>
        <td class="num dn"><?=fmtN($row['r2_dmg'])?></td>
        <td class="num"><?=$row['qty']?></td>
      </tr>
      <?php endforeach;?>
      <?php if(empty($r['onlyR2'])):?>
      <tr><td colspan="8" class="empty">All Report 2 records matched in Report 1.</td></tr>
      <?php endif;?>
    </tbody>
    <?php if(!empty($r['onlyR2'])):?>
    <tfoot><tr>
      <td colspan="5" style="font-size:11px;color:var(--ink3);">TOTAL — <?=$cntR2?> unmatched keys</td>
      <td class="num dn"><?=fmtN($r2tot)?></td>
      <td></td>
    </tr></tfoot>
    <?php endif;?>
  </table>
  </div>
</div>
</div>

<?php
$aiPayload=[
  'summary'=>[
    'r1_total_tur'      => round($r['tot1'],2),
    'r2_damage_total'   => round($r['tot2'],2),
    'net_difference'    => round($netDiff,2),
    'matched_keys'      => $cntM,
    'exact_matches'     => $cntE,
    'keys_with_variance'=> $cntV,
    'only_r1_keys'      => $cntR1,
    'only_r1_tur'       => round($r1tot,2),
    'only_r2_keys'      => $cntR2,
    'only_r2_dmg'       => round($r2tot,2),
  ],
  'top_variances' => array_slice(array_map(fn($x)=>[
    'retailer'     => $x['RC'],
    'product'      => $x['PC'],
    'tur'          => $x['TUR'],
    'product_name' => $x['PN'],
    'r1_tur'       => round($x['r1_tur'],2),
    'r2_dmg'       => round($x['r2_dmg'],2),
    'diff'         => round($x['diff'],2),
    'return_types' => $x['RT'],
  ], $r['matched']), 0, 15),
  'only_r1_top' => array_slice(array_map(fn($x)=>[
    'retailer' => $x['RC'], 'product' => $x['PC'],
    'tur'      => $x['TUR'], 'r1_tur' => round($x['r1_tur'],2),
    'rt'       => implode(',',$x['RT']),
  ], $r['onlyR1']), 0, 10),
  'only_r2_top' => array_slice(array_map(fn($x)=>[
    'outlet'  => $x['RC'], 'product' => $x['PC'],
    'tur'     => $x['TUR'], 'r2_dmg' => round($x['r2_dmg'],2),
  ], $r['onlyR2']), 0, 10),
];
?>
<script>
const AI_PAYLOAD  = <?=json_encode($aiPayload, JSON_UNESCAPED_UNICODE)?>;
const GEMINI_KEY  = <?=json_encode($GEMINI_KEY)?>;
const GEMINI_SET  = <?=$GEMINI_KEY_SET ? 'true' : 'false'?>;

/* ── tab / expand / filter helpers ── */
function showTab(name, el) {
  document.querySelectorAll('.spanel').forEach(p => p.classList.remove('on'));
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('on'));
  document.getElementById('pan-' + name).classList.add('on');
  el.classList.add('on');
}
function tx(id) {
  const el  = document.getElementById(id);
  el.classList.toggle('on');
  const tog = el.closest('tr').previousElementSibling?.querySelector('.xtog');
  if (tog) tog.textContent = el.classList.contains('on') ? '▼' : '▶';
}
function ft(tid, q) {
  q = q.toLowerCase();
  document.querySelectorAll('#' + tid + ' tbody tr:not(.xrow)').forEach(r => {
    const show = r.textContent.toLowerCase().includes(q);
    r.style.display = show ? '' : 'none';
    const nx = r.nextElementSibling;
    if (nx?.classList.contains('xrow')) nx.style.display = r.style.display;
  });
}
function onFile(inp, dzId, fnId) {
  const f = inp.files[0]; if (!f) return;
  const fn = document.getElementById(fnId);
  fn.querySelector('span').textContent = f.name; fn.style.display = 'flex';
  const dz = document.getElementById(dzId);
  dz.style.borderColor = 'var(--green)'; dz.style.background = 'var(--green-l)';
}
['dz1','dz2'].forEach(id => {
  const dz = document.getElementById(id); if (!dz) return;
  dz.addEventListener('dragover',  e => { e.preventDefault(); dz.classList.add('over'); });
  dz.addEventListener('dragleave', ()  => dz.classList.remove('over'));
  dz.addEventListener('drop',      e => { e.preventDefault(); dz.classList.remove('over'); });
});
document.getElementById('uf')?.addEventListener('submit', () => {
  const b = document.getElementById('gobtn');
  b.disabled = true;
  b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing…';
});

/* ══════════════════════════════════════════════
   Gemini AI Analysis
   Model : gemini-1.5-flash
   Endpoint : generativelanguage.googleapis.com
══════════════════════════════════════════════ */
async function runAI() {
  const btn = document.getElementById('aibtn');
  const out = document.getElementById('aiOut');
  const ld  = document.getElementById('aild');

  if (!GEMINI_SET || !GEMINI_KEY) {
    out.style.display = 'block';
    out.innerHTML = '<span style="color:#fbbf24;"><i class="fa-solid fa-triangle-exclamation"></i> '
      + 'No Gemini API key configured. Go to <strong>Settings → AI Settings</strong> '
      + 'and save your Google Gemini API key.</span>';
    return;
  }

  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Analysing…';
  ld.style.display = 'flex';
  out.style.display = 'none';
  out.textContent = '';

  const prompt = `You are a senior supply-chain analyst for an FMCG distributor in Sri Lanka.
Analyse this TUR reconciliation data (Damage/Shortage Proposal vs Invoice Wise Sales).
Match key: Retailer/Outlet Code + Product Code + TUR rate (no batch matching).
All monetary values are in LKR.

DATA:
${JSON.stringify(AI_PAYLOAD, null, 2)}

Provide a structured analysis with these sections:

**1. Executive Summary**
Overall reconciliation health, key totals, and whether the books balance.

**2. Key Variances**
Top matched records with the largest R1 vs R2 difference. For each, state the probable root cause (timing, data entry, quantity dispute, etc.).

**3. Unmatched Records Analysis**
What the ${AI_PAYLOAD.summary.only_r1_keys} only-R1 keys (LKR ${AI_PAYLOAD.summary.only_r1_tur}) and ${AI_PAYLOAD.summary.only_r2_keys} only-R2 keys (LKR ${AI_PAYLOAD.summary.only_r2_dmg}) suggest operationally. Are these likely pending approvals, missing invoices, or system gaps?

**4. Risk Flags**
Flag any patterns suggesting over-claiming, under-recording, batch code mismatches, or data quality issues. Quantify where possible.

**5. Recommendations**
3–5 specific, actionable steps the team should take to resolve the variances and prevent recurrence.

**6. Priority Actions This Week**
The single most important thing to do first, and why.`;

  /* Model fallback list — tried in order until one succeeds */
  const MODELS = [
    'gemini-2.0-flash',
    'gemini-2.5-flash',
    'gemini-2.0-flash-lite',
    'gemini-1.5-flash-latest',
  ];

  const BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';
  const BODY = JSON.stringify({
    contents: [{ parts: [{ text: prompt }] }],
    generationConfig: { maxOutputTokens: 1800, temperature: 0.3 }
  });

  async function tryModel(model) {
    const url = `${BASE}${model}:generateContent?key=${GEMINI_KEY}`;
    const resp = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: BODY
    });
    const data = await resp.json();
    if (!resp.ok) {
      /* 404 = model not available — signal caller to try next */
      const msg = data?.error?.message || 'HTTP ' + resp.status;
      const notFound = resp.status === 404 || msg.toLowerCase().includes('not found');
      throw { notFound, message: msg };
    }
    return data;
  }

  try {
    let data = null;
    let usedModel = '';
    let lastErr = '';

    for (const model of MODELS) {
      try {
        data = await tryModel(model);
        usedModel = model;
        break;
      } catch (e) {
        lastErr = e.message || String(e);
        if (!e.notFound) throw new Error(lastErr); // real error — stop immediately
        /* 404 — model unavailable, try next */
      }
    }

    if (!data) throw new Error('No available Gemini model found. Last error: ' + lastErr);

    const text = (data?.candidates?.[0]?.content?.parts || [])
      .map(p => p.text || '').join('\n').trim();

    if (!text) throw new Error('Empty response from Gemini (' + usedModel + ')');

    ld.style.display  = 'none';
    out.style.display = 'block';
    out.innerHTML = `<div style="font-size:10px;color:rgba(255,255,255,.3);margin-bottom:8px;">
        <i class="fa-solid fa-microchip"></i> Model: ${usedModel}
      </div>`
      + text
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/^#{1,3}\s+(.+)/gm, '<br><strong style="font-size:13px;color:#93c5fd;">$1</strong><br>')
        .replace(/\n/g, '<br>');

    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-rotate-right"></i> Re-Analyse';

  } catch (err) {
    ld.style.display  = 'none';
    out.style.display = 'block';
    out.innerHTML = `<span style="color:#f87171;">
      <i class="fa-solid fa-circle-exclamation"></i>
      Gemini API error: <strong>${err.message || err}</strong><br>
      Check your API key in <strong>Settings → AI Settings</strong>.
      Keys are available free at <a href="https://aistudio.google.com/app/apikey" target="_blank"
        style="color:#93c5fd;">aistudio.google.com</a>.
    </span>`;
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-sparkles"></i> Retry';
  }
}
</script>

<?php endif; ?>
</div>
<?php include 'footer.php'; ?>
</body>
</html>
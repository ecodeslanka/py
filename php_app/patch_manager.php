<?php
/**
 * patch_manager.php — Web-based patch apply / reverse tool
 * Place alongside return_cheques.php and return_cheques_issue.patch
 *
 * Pure PHP: no shell exec, no patch binary needed.
 */
session_start();

define('PATCH_FILE',  __DIR__ . '/return_cheques_issue.patch');
define('TARGET_FILE', __DIR__ . '/return_cheques.php');
define('PATCH_SIGNAL','openIssueDrawer');
define('PAGE_PASS',   '');

if (PAGE_PASS !== '') {
    if (isset($_POST['ppass'])) {
        if ($_POST['ppass'] === PAGE_PASS) $_SESSION['pm_auth'] = true;
        else { $_SESSION['pm_auth'] = false; header('Location: '.$_SERVER['PHP_SELF']); exit; }
    }
    if (empty($_SESSION['pm_auth'])) { ?>
<!DOCTYPE html><html><head><title>Patch Manager</title><meta charset="UTF-8">
<style>body{font-family:system-ui;background:#0f172a;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}.b{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:32px;width:320px}h2{color:#e2e8f0;font-size:18px;margin:0 0 20px}input{width:100%;padding:9px;border:1px solid #475569;border-radius:7px;background:#0f172a;color:#e2e8f0;font-size:14px;box-sizing:border-box;margin-bottom:14px}button{width:100%;padding:10px;background:#6366f1;color:#fff;border:none;border-radius:7px;font-size:14px;font-weight:700;cursor:pointer}</style>
</head><body><div class="b"><h2>Patch Manager</h2><form method="POST"><input type="password" name="ppass" placeholder="Password" autofocus><button>Enter</button></form></div></body></html>
<?php exit; } }

/* ══════════ PARSE PATCH ══════════ */
function parse_patch(string $c): array {
    $lines = explode("\n", $c);
    $hunks = []; $cur = null;
    foreach ($lines as $l) {
        if (str_starts_with($l, '@@')) {
            if ($cur) $hunks[] = $cur;
            preg_match('/^@@[^@]*@@\s*(.*)$/', $l, $m);
            $cur = ['header'=>$l,'raw'=>[],'label'=>trim($m[1]??'')];
        } elseif ($cur && !str_starts_with($l,'---') && !str_starts_with($l,'+++')) {
            $cur['raw'][] = $l;
        }
    }
    if ($cur) $hunks[] = $cur;
    foreach ($hunks as &$h) {
        $fp = null; $lp = null;
        foreach ($h['raw'] as $i=>$l) { if (str_starts_with($l,'+')) { if ($fp===null)$fp=$i; $lp=$i; } }
        $h['ctx_before']=$h['additions']=$h['ctx_after']=[];
        if ($fp===null){unset($h['raw']);continue;}
        for($i=0;$i<$fp;$i++) if(str_starts_with($h['raw'][$i],' '))$h['ctx_before'][]=substr($h['raw'][$i],1);
        for($i=$fp;$i<=$lp;$i++) if(str_starts_with($h['raw'][$i],'+'))$h['additions'][]=substr($h['raw'][$i],1);
        for($i=$lp+1;$i<count($h['raw']);$i++) if(str_starts_with($h['raw'][$i],' '))$h['ctx_after'][]=substr($h['raw'][$i],1);
        unset($h['raw']);
    }
    unset($h);
    return $hunks;
}

/* ══════════ FIND INSERTION POINT ══════════
   Finds line index of first ctx_after line (searching from end for uniqueness). */
function find_insert(array $lines, array $ctx_after): int {
    if (empty($ctx_after)) return -1;
    $needle = rtrim($ctx_after[0]);
    for ($i = count($lines)-1; $i >= 0; $i--) {
        if (rtrim($lines[$i]) === $needle) {
            $ok = true;
            for ($j=1;$j<count($ctx_after);$j++) {
                if (!isset($lines[$i+$j])||rtrim($lines[$i+$j])!==rtrim($ctx_after[$j])){$ok=false;break;}
            }
            if ($ok) return $i;
        }
    }
    return -1;
}

/* ══════════ APPLY ══════════ */
function do_apply(array $lines, array $hunks): array {
    $log=[];
    foreach ($hunks as $i=>$h) {
        $pos = find_insert($lines, $h['ctx_after']);
        if ($pos===-1) return ['ok'=>false,'log'=>[['s'=>'err','m'=>"Hunk ".($i+1)." ({$h['label']}): context not found"]]];
        $ins = array_map(fn($l)=>$l."\n", $h['additions']);
        array_splice($lines,$pos,0,$ins);
        $log[]=['s'=>'ok','m'=>"Hunk ".($i+1)." ({$h['label']}): +".count($h['additions'])." lines at line ".($pos+1)];
    }
    return ['ok'=>true,'log'=>$log,'lines'=>$lines];
}

/* ══════════ REVERSE ══════════ */
function do_reverse(array $lines, array $hunks): array {
    $log=[];
    foreach (array_reverse($hunks,true) as $i=>$h) {
        if (empty($h['additions'])){$log[]=['s'=>'skip','m'=>"Hunk ".($i+1).": no additions"];continue;}
        $needle = rtrim($h['additions'][0]);
        $found=false;
        for($j=count($lines)-1;$j>=0;$j--){
            if(rtrim($lines[$j])===$needle){
                $ok=true;
                for($k=1;$k<count($h['additions']);$k++){
                    if(!isset($lines[$j+$k])||rtrim($lines[$j+$k])!==rtrim($h['additions'][$k])){$ok=false;break;}
                }
                if($ok){array_splice($lines,$j,count($h['additions']));$log[]=['s'=>'ok','m'=>"Hunk ".($i+1)." ({$h['label']}): removed ".count($h['additions'])." lines from line ".($j+1)];$found=true;break;}
            }
        }
        if(!$found)return['ok'=>false,'log'=>[['s'=>'err','m'=>"Hunk ".($i+1)." ({$h['label']}): additions not found — patch may not be applied"]]];
    }
    return['ok'=>true,'log'=>$log,'lines'=>$lines];
}

/* ══════════ STATUS ══════════ */
function get_status(): array {
    $s=['target_exists'=>file_exists(TARGET_FILE),'target_readable'=>is_readable(TARGET_FILE),
        'target_writable'=>is_writable(TARGET_FILE),'patch_exists'=>file_exists(PATCH_FILE),
        'patch_readable'=>is_readable(PATCH_FILE),'is_patched'=>false,'target_size'=>0,'target_mtime'=>0,'backups'=>[]];
    if($s['target_exists']&&$s['target_readable']){
        $c=file_get_contents(TARGET_FILE);
        $s['is_patched']=str_contains($c,PATCH_SIGNAL);
        $s['target_size']=strlen($c);$s['target_mtime']=filemtime(TARGET_FILE);
    }
    $dir=dirname(TARGET_FILE);$base=basename(TARGET_FILE);
    $backs=array_merge(glob($dir.'/'.$base.'.orig_*')?:[],glob($dir.'/'.$base.'.patched_*')?:[]);
    foreach($backs as $f)$s['backups'][]=['path'=>$f,'size'=>filesize($f),'mtime'=>filemtime($f)];
    usort($s['backups'],fn($a,$b)=>$b['mtime']<=>$a['mtime']);
    return $s;
}

/* ══════════ HANDLE POST ══════════ */
$action=$_POST['action']??'';
$message='';$msg_type='info';$op_log=[];

if(in_array($action,['apply','reverse','restore'])){
    if(!file_exists(TARGET_FILE)){$message='Target file not found: '.TARGET_FILE;$msg_type='error';}
    elseif(!is_writable(TARGET_FILE)){$message='Target file not writable. Run: chmod 644 '.basename(TARGET_FILE);$msg_type='error';}
    elseif(!file_exists(PATCH_FILE)&&$action!=='restore'){$message='Patch file not found: '.PATCH_FILE;$msg_type='error';}
    elseif($action==='restore'){
        $bp=$_POST['backup_path']??'';
        $real=realpath($bp);$rdir=realpath(dirname(TARGET_FILE));
        if(!$real||!str_starts_with($real,$rdir)){$message='Invalid backup path.';$msg_type='error';}
        elseif(!file_exists($real)){$message='Backup not found.';$msg_type='error';}
        else{@copy($real,TARGET_FILE)?($message='Restored: '.basename($real).' ✓',$msg_type='success'):($message='Restore failed.',$msg_type='error');}
    }else{
        $pc=file_get_contents(PATCH_FILE);$fc=file_get_contents(TARGET_FILE);
        $fl=explode("\n",str_replace("\r","",$fc));
        $hunks=parse_patch($pc);$is_pat=str_contains($fc,PATCH_SIGNAL);
        if($action==='apply'){
            if($is_pat){$message='Patch already applied. Reverse first.';$msg_type='warning';}
            else{
                $r=do_apply($fl,$hunks);$op_log=$r['log'];
                if($r['ok']){
                    $bk=TARGET_FILE.'.orig_'.date('Ymd_His');copy(TARGET_FILE,$bk);
                    $nc=implode("\n",array_map(fn($l)=>rtrim($l,"\n"),$r['lines']));
                    file_put_contents(TARGET_FILE,$nc)!==false?($message='Patch applied ✓ — backup: '.basename($bk),$msg_type='success'):($message='Write failed — check permissions.',$msg_type='error');
                }else{$message='Apply failed — see log.';$msg_type='error';}
            }
        }elseif($action==='reverse'){
            if(!$is_pat){$message='Patch not applied — nothing to reverse.';$msg_type='warning';}
            else{
                $r=do_reverse($fl,$hunks);$op_log=$r['log'];
                if($r['ok']){
                    $bk=TARGET_FILE.'.patched_'.date('Ymd_His');copy(TARGET_FILE,$bk);
                    $nc=implode("\n",array_map(fn($l)=>rtrim($l,"\n"),$r['lines']));
                    file_put_contents(TARGET_FILE,$nc)!==false?($message='Patch reversed ✓ — backup: '.basename($bk),$msg_type='success'):($message='Write failed.',$msg_type='error');
                }else{$message='Reverse failed — see log.';$msg_type='error';}
            }
        }
    }
}

$status=get_status();
$patch_raw=file_exists(PATCH_FILE)?file_get_contents(PATCH_FILE):'';
$hunks_pv=$patch_raw?parse_patch($patch_raw):[];

function diff_html(array $hunks,int $max=60):string{
    $o='';
    foreach($hunks as $i=>$h){
        $o.='<div class="hb"><div class="hh"><span class="hn">Hunk '.($i+1).'</span>';
        if($h['label'])$o.='<span class="hl">'.htmlspecialchars($h['label']).'</span>';
        $o.='<span class="hm">+'.count($h['additions']).' lines</span></div><div class="hls">';
        foreach($h['ctx_before'] as $l)$o.='<div class="dc"> '.htmlspecialchars($l).'</div>';
        $shown=0;
        foreach($h['additions'] as $l){
            if($shown>=$max){$o.='<div class="dm">… '.(count($h['additions'])-$shown).' more lines …</div>';break;}
            $o.='<div class="da">+'.htmlspecialchars($l).'</div>';$shown++;
        }
        foreach($h['ctx_after'] as $l)$o.='<div class="dc"> '.htmlspecialchars($l).'</div>';
        $o.='</div></div>';
    }
    return $o;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Patch Manager — return_cheques.php</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#0f172a;--sf:#1e293b;--sf2:#263244;--bd:#334155;--bd2:#475569;--tx:#e2e8f0;--tx2:#94a3b8;--tx3:#64748b;--gn:#22c55e;--rd:#ef4444;--am:#f59e0b;--vi:#a78bfa;--bl:#60a5fa;--r:10px;--rs:7px}
body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--tx);min-height:100vh;font-size:14px;line-height:1.5}
.wrap{max-width:980px;margin:0 auto;padding:28px 18px}
/* header */
.ph{display:flex;align-items:center;justify-content:space-between;margin-bottom:26px;flex-wrap:wrap;gap:12px}
.pt{font-size:21px;font-weight:800;display:flex;align-items:center;gap:10px}
.pt i{color:var(--vi)}
.ps{font-size:12px;color:var(--tx3);margin-top:3px}
/* status banner */
.sb{display:flex;align-items:center;gap:16px;padding:15px 20px;border-radius:var(--r);margin-bottom:20px;border:1px solid}
.sb.p{background:rgba(34,197,94,.07);border-color:#166534;color:var(--gn)}
.sb.u{background:rgba(167,139,250,.07);border-color:#5b21b6;color:var(--vi)}
.sb-ico{font-size:26px;flex-shrink:0}
.sb-title{font-size:15px;font-weight:800}
.sb-sub{font-size:12px;opacity:.8;margin-top:3px}
/* info grid */
.ig{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:12px;margin-bottom:20px}
.ib{background:var(--sf2);border:1px solid var(--bd);border-radius:var(--rs);padding:11px 15px}
.il{font-size:10px;font-weight:700;color:var(--tx3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;display:flex;align-items:center;gap:5px}
.iv{font-size:13px;font-weight:600;color:var(--tx);word-break:break-all}
.iv.ok{color:var(--gn)}.iv.warn{color:var(--am)}.iv.err{color:var(--rd)}.iv.mn{font-family:monospace;font-size:12px}
/* checks */
.cr{display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:1px solid var(--bd);font-size:13px}
.cr:last-child{border-bottom:none}
.ci{width:18px;height:18px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;flex-shrink:0}
.ci.ok{background:rgba(34,197,94,.14);color:var(--gn)}
.ci.err{background:rgba(239,68,68,.14);color:var(--rd)}
.ci.info{background:rgba(167,139,250,.14);color:var(--vi)}
.cl{flex:1;color:var(--tx2)}.cv{font-size:11px;color:var(--tx3)}.cv.err{color:var(--rd)}
/* buttons */
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border:none;border-radius:var(--rs);font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap}
.btn:disabled{opacity:.38;cursor:not-allowed;transform:none!important;box-shadow:none!important}
.ba{background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;box-shadow:0 4px 14px rgba(99,102,241,.3)}
.ba:hover:not(:disabled){background:linear-gradient(135deg,#4338ca,#6d28d9);transform:translateY(-1px)}
.br{background:linear-gradient(135deg,#dc2626,#ef4444);color:#fff;box-shadow:0 4px 14px rgba(220,38,38,.25)}
.br:hover:not(:disabled){background:linear-gradient(135deg,#b91c1c,#dc2626);transform:translateY(-1px)}
.bs{background:var(--sf2);color:var(--tx2);border:1px solid var(--bd2)}
.bs:hover:not(:disabled){background:var(--bd);color:var(--tx)}
.bds{background:rgba(239,68,68,.1);color:var(--rd);border:1px solid rgba(239,68,68,.28);padding:4px 10px;font-size:11px;border-radius:5px;cursor:pointer;font-family:inherit;transition:all .15s}
.bds:hover{background:rgba(239,68,68,.2)}
/* action area */
.aa{display:flex;gap:12px;flex-wrap:wrap;align-items:center;padding:18px;background:var(--sf2);border-radius:var(--r);border:1px solid var(--bd)}
.div{width:1px;height:36px;background:var(--bd2)}
/* message */
.mb{display:flex;align-items:flex-start;gap:12px;padding:13px 16px;border-radius:var(--rs);border:1px solid;margin-bottom:20px;font-size:13px;font-weight:500}
.mb.success{background:rgba(34,197,94,.07);border-color:#166534;color:#86efac}
.mb.error{background:rgba(239,68,68,.07);border-color:#991b1b;color:#fca5a5}
.mb.warning{background:rgba(245,158,11,.07);border-color:#92400e;color:#fcd34d}
.mb.info{background:rgba(96,165,250,.07);border-color:#1d4ed8;color:#93c5fd}
.mi{font-size:16px;flex-shrink:0;margin-top:1px}
/* op log */
.ol{margin-top:12px}
.ol-t{font-size:11px;font-weight:700;color:var(--tx3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:7px}
.oe{display:flex;align-items:center;gap:8px;padding:5px 10px;border-radius:5px;margin-bottom:3px;font-size:12px;font-family:monospace}
.oe.ok{background:rgba(34,197,94,.06);color:#86efac}
.oe.err{background:rgba(239,68,68,.07);color:#fca5a5}
.oe.skip{background:rgba(96,165,250,.06);color:#93c5fd}
/* card */
.card{background:var(--sf);border:1px solid var(--bd);border-radius:var(--r);overflow:hidden;margin-bottom:20px}
.ch{padding:0;border-bottom:1px solid var(--bd)}
.cb{padding:16px 18px}
/* tabs */
.tabs{display:flex}
.tb{padding:9px 16px;background:none;border:none;border-bottom:2px solid transparent;color:var(--tx3);font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .15s;display:flex;align-items:center;gap:6px;margin-bottom:-1px}
.tb:hover{color:var(--tx2)}
.tb.active{color:var(--vi);border-bottom-color:var(--vi)}
.tp{display:none}.tp.active{display:block}
/* pills */
.pill{padding:2px 9px;border-radius:10px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px}
.pg{background:rgba(34,197,94,.13);color:var(--gn);border:1px solid rgba(34,197,94,.28)}
.pv{background:rgba(167,139,250,.13);color:var(--vi);border:1px solid rgba(167,139,250,.28)}
.pr{background:rgba(239,68,68,.13);color:var(--rd);border:1px solid rgba(239,68,68,.28)}
/* diff */
.dw{background:#0a0f1a;border-radius:0;overflow:hidden}
.dt{display:flex;align-items:center;justify-content:space-between;padding:9px 16px;background:var(--sf2);border-bottom:1px solid var(--bd)}
.dtt{font-size:12px;font-weight:700;color:var(--tx2);display:flex;align-items:center;gap:7px}
.dc-wrap{max-height:420px;overflow-y:auto}
.hb{border-bottom:2px solid var(--bd)}.hb:last-child{border-bottom:none}
.hh{display:flex;align-items:center;gap:8px;padding:7px 16px;background:#1a2035;border-bottom:1px solid var(--bd)}
.hn{background:rgba(167,139,250,.15);color:var(--vi);border:1px solid rgba(167,139,250,.3);padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700}
.hl{font-size:11px;color:var(--tx3);font-style:italic;flex:1}
.hm{font-size:11px;color:var(--gn);font-weight:700}
.hls{font-family:'Fira Code','Cascadia Code',monospace;font-size:11.5px}
.dc{padding:2px 16px;color:var(--tx3);white-space:pre}
.da{padding:2px 16px;color:#86efac;background:rgba(34,197,94,.055);white-space:pre;border-left:3px solid rgba(34,197,94,.35)}
.dm{padding:4px 16px;color:var(--tx3);font-style:italic;font-size:11px;background:rgba(99,102,241,.04)}
/* backups */
.bt{width:100%;border-collapse:collapse;font-size:12.5px}
.bt th{padding:8px 12px;text-align:left;font-size:10px;font-weight:700;color:var(--tx3);text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid var(--bd);background:var(--sf2)}
.bt td{padding:8px 12px;border-bottom:1px solid var(--bd);color:var(--tx2);vertical-align:middle}
.bt tr:last-child td{border-bottom:none}
.bt tr:hover td{background:rgba(255,255,255,.02)}
.bkt{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:700}
.bko{background:rgba(167,139,250,.12);color:var(--vi);border:1px solid rgba(167,139,250,.28)}
.bkp{background:rgba(34,197,94,.1);color:var(--gn);border:1px solid rgba(34,197,94,.25)}
.bkn{font-family:monospace;font-size:11.5px;color:var(--tx)}
.be{text-align:center;padding:30px;color:var(--tx3);font-size:13px}
/* confirm overlay */
.co{display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:9999;align-items:center;justify-content:center}
.co.open{display:flex}
.cb2{background:var(--sf);border:1px solid var(--bd2);border-radius:14px;width:420px;max-width:95vw;overflow:hidden;box-shadow:0 24px 80px rgba(0,0,0,.5)}
.ch2{padding:15px 20px;display:flex;align-items:center;gap:10px}
.ch2.a{background:rgba(99,102,241,.14);border-bottom:1px solid rgba(99,102,241,.3)}
.ch2.r{background:rgba(220,38,38,.12);border-bottom:1px solid rgba(220,38,38,.3)}
.ch2.a i,.ch2.a span{color:var(--vi)}
.ch2.r i,.ch2.r span{color:var(--rd)}
.ch2 span{font-size:15px;font-weight:800}
.cb2-b{padding:18px 20px}
.cb2-b p{font-size:13px;color:var(--tx2);margin-bottom:10px;line-height:1.6}
.fc{font-family:monospace;background:var(--sf2);border:1px solid var(--bd2);padding:4px 10px;border-radius:5px;font-size:12px;color:var(--tx);display:inline-block;margin:4px 0}
.cb2-b ul{padding-left:18px;margin:6px 0;font-size:12.5px;color:var(--tx3);line-height:1.9}
.cb2-f{padding:13px 20px;border-top:1px solid var(--bd);display:flex;justify-content:flex-end;gap:8px}
.bc{padding:8px 16px;border-radius:6px;border:1px solid var(--bd2);background:transparent;color:var(--tx2);font-size:13px;font-weight:600;cursor:pointer;font-family:inherit}
.bc:hover{background:var(--sf2)}
/* info boxes */
.ib2{background:var(--sf2);border:1px solid var(--bd);border-radius:var(--rs);padding:13px 16px}
.ib2-t{font-size:13px;font-weight:700;color:var(--tx);margin-bottom:8px;display:flex;align-items:center;gap:7px}
.ib2 ol{padding-left:18px;font-size:12.5px;color:var(--tx2);line-height:2}
.ib2 p{font-size:12.5px;color:var(--tx2);line-height:1.7}
code{background:var(--sf);padding:1px 5px;border-radius:3px;font-size:11px;font-family:monospace}
::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--bd2);border-radius:3px}
@media(max-width:640px){.ig{grid-template-columns:1fr 1fr}.aa{flex-direction:column;align-items:stretch}.div{display:none}.btn{justify-content:center}}
</style>
</head>
<body>
<div class="wrap">

<div class="ph">
    <div>
        <div class="pt"><i class="fa-solid fa-code-merge"></i> Patch Manager</div>
        <div class="ps">return_cheques.php — Cheque Issue Feature</div>
    </div>
    <span class="pill <?php echo $status['is_patched']?'pg':'pv'; ?>" style="font-size:12px;padding:5px 13px;">
        <i class="fa-solid <?php echo $status['is_patched']?'fa-circle-check':'fa-circle-xmark'; ?>"></i>
        <?php echo $status['is_patched']?'Patched':'Unpatched'; ?>
    </span>
</div>

<?php if($message): ?>
<div class="mb <?php echo $msg_type; ?>">
    <i class="fa-solid mi <?php echo match($msg_type){'success'=>'fa-circle-check','error'=>'fa-triangle-exclamation','warning'=>'fa-triangle-exclamation',default=>'fa-circle-info'}; ?>"></i>
    <div>
        <?php echo htmlspecialchars($message); ?>
        <?php if($op_log): ?>
        <div class="ol">
            <div class="ol-t"><i class="fa-solid fa-list-check"></i> Operation log</div>
            <?php foreach($op_log as $e): ?>
            <div class="oe <?php echo $e['s']; ?>">
                <i class="fa-solid <?php echo match($e['s']){'ok'=>'fa-check','err'=>'fa-xmark',default=>'fa-minus'}; ?>"></i>
                <?php echo htmlspecialchars($e['m']); ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="sb <?php echo $status['is_patched']?'p':'u'; ?>">
    <div class="sb-ico"><i class="fa-solid <?php echo $status['is_patched']?'fa-shield-check':'fa-code-merge'; ?>"></i></div>
    <div>
        <?php if($status['is_patched']): ?>
        <div class="sb-title">Patch is applied</div>
        <div class="sb-sub">Cheque issue feature is active. Use <strong>Reverse Patch</strong> to remove it.</div>
        <?php else: ?>
        <div class="sb-title">Patch not applied</div>
        <div class="sb-sub">Feature is not in return_cheques.php yet. Use <strong>Apply Patch</strong> to add it.</div>
        <?php endif; ?>
    </div>
</div>

<div class="ig">
    <div class="ib"><div class="il"><i class="fa-solid fa-file-code"></i> Target file</div><div class="iv mn"><?php echo basename(TARGET_FILE); ?></div><div style="font-size:10px;color:var(--tx3);margin-top:2px;"><?php echo dirname(TARGET_FILE); ?></div></div>
    <div class="ib"><div class="il"><i class="fa-solid fa-file-lines"></i> Patch file</div><div class="iv mn"><?php echo basename(PATCH_FILE); ?></div><div style="font-size:10px;color:var(--tx3);margin-top:2px;"><?php echo $status['patch_exists']?number_format(filesize(PATCH_FILE)).' bytes · '.count($hunks_pv).' hunks':'Not found'; ?></div></div>
    <div class="ib"><div class="il"><i class="fa-solid fa-weight-scale"></i> Target size</div><div class="iv"><?php echo $status['target_exists']?number_format($status['target_size']).' bytes':'—'; ?></div><?php if($status['target_mtime']): ?><div style="font-size:10px;color:var(--tx3);margin-top:2px;"><?php echo date('d M Y H:i',$status['target_mtime']); ?></div><?php endif; ?></div>
    <div class="ib"><div class="il"><i class="fa-solid fa-hard-drive"></i> Backups</div><div class="iv"><?php echo count($status['backups']); ?> saved</div><?php if($status['backups']): ?><div style="font-size:10px;color:var(--tx3);margin-top:2px;">Latest: <?php echo date('d M H:i',$status['backups'][0]['mtime']); ?></div><?php endif; ?></div>
</div>

<div class="card" style="margin-bottom:18px;">
    <div class="cb" style="padding:10px 16px;">
        <?php
        $chks=[
            ['l'=>'Target file exists','ok'=>$status['target_exists'],'v'=>TARGET_FILE],
            ['l'=>'Target readable','ok'=>$status['target_readable'],'v'=>$status['target_readable']?'Yes':'No — check permissions'],
            ['l'=>'Target writable','ok'=>$status['target_writable'],'v'=>$status['target_writable']?'Yes':'No — chmod 644 '.basename(TARGET_FILE)],
            ['l'=>'Patch file exists','ok'=>$status['patch_exists'],'v'=>PATCH_FILE],
            ['l'=>'Patch readable','ok'=>$status['patch_readable'],'v'=>$status['patch_readable']?'Yes':'No'],
            ['l'=>'save_cheque_issue.php','ok'=>file_exists(__DIR__.'/save_cheque_issue.php'),'v'=>file_exists(__DIR__.'/save_cheque_issue.php')?'Present':'MISSING — deploy alongside'],
            ['l'=>'Patch status','ok'=>true,'v'=>$status['is_patched']?'Applied':'Not applied','t'=>'info'],
        ];
        foreach($chks as $c):$t=$c['t']??($c['ok']?'ok':'err');
        ?>
        <div class="cr">
            <div class="ci <?php echo $t; ?>"><i class="fa-solid <?php echo $t==='ok'?'fa-check':($t==='info'?'fa-info':'fa-xmark'); ?>"></i></div>
            <span class="cl"><?php echo htmlspecialchars($c['l']); ?></span>
            <span class="cv <?php echo $t==='err'?'err':''; ?>"><?php echo htmlspecialchars($c['v']); ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php $can=($status['target_exists']&&$status['target_writable']&&$status['target_readable']&&$status['patch_exists']&&$status['patch_readable']); ?>
<div class="aa">
    <button class="btn ba" <?php echo($can&&!$status['is_patched'])?'':'disabled'; ?> onclick="openConf('apply')">
        <i class="fa-solid fa-code-merge"></i> Apply Patch
    </button>
    <div class="div"></div>
    <button class="btn br" <?php echo($can&&$status['is_patched'])?'':'disabled'; ?> onclick="openConf('reverse')">
        <i class="fa-solid fa-rotate-left"></i> Reverse Patch
    </button>
    <?php if(!$can): ?>
    <span style="font-size:12px;color:var(--am);display:flex;align-items:center;gap:5px;"><i class="fa-solid fa-triangle-exclamation"></i> Fix pre-flight errors above first.</span>
    <?php endif; ?>
</div>

<div class="card" style="margin-top:18px;">
    <div class="ch">
        <div class="tabs">
            <button class="tb active" onclick="swTab('diff',this)"><i class="fa-solid fa-code-compare"></i> Patch diff <span class="pill pv"><?php echo count($hunks_pv); ?> hunks</span></button>
            <button class="tb" onclick="swTab('bk',this)"><i class="fa-solid fa-clock-rotate-left"></i> Backups <span class="pill <?php echo count($status['backups'])?'pg':'pv'; ?>"><?php echo count($status['backups']); ?></span></button>
            <button class="tb" onclick="swTab('how',this)"><i class="fa-solid fa-circle-info"></i> Guide</button>
        </div>
    </div>

    <div class="tp active" id="tab-diff">
        <?php if($hunks_pv): ?>
        <div class="dw">
            <div class="dt">
                <div class="dtt"><i class="fa-solid fa-code" style="color:var(--vi);"></i><?php echo basename(PATCH_FILE); ?><span class="pill pv"><?php echo count($hunks_pv); ?> hunks</span><span class="pill pg">+<?php echo array_sum(array_map(fn($h)=>count($h['additions']),$hunks_pv)); ?> lines</span></div>
                <span style="font-size:11px;color:var(--tx3);">Pure additions — 0 deletions</span>
            </div>
            <div class="dc-wrap"><?php echo diff_html($hunks_pv,60); ?></div>
        </div>
        <?php else: ?>
        <div style="padding:40px;text-align:center;color:var(--tx3);"><i class="fa-solid fa-file-circle-question" style="font-size:28px;display:block;margin-bottom:10px;opacity:.4;"></i>Patch file not found.</div>
        <?php endif; ?>
    </div>

    <div class="tp" id="tab-bk">
        <?php if($status['backups']): ?>
        <table class="bt">
            <thead><tr><th>Type</th><th>Filename</th><th>Size</th><th>Created</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach($status['backups'] as $bk):$bn=basename($bk['path']);$io=str_contains($bn,'.orig_'); ?>
            <tr>
                <td><span class="bkt <?php echo $io?'bko':'bkp'; ?>"><i class="fa-solid <?php echo $io?'fa-arrow-rotate-left':'fa-code-merge'; ?>"></i><?php echo $io?'Original':'Patched'; ?></span></td>
                <td><span class="bkn"><?php echo htmlspecialchars($bn); ?></span></td>
                <td style="color:var(--tx3);font-size:12px;"><?php echo number_format($bk['size']); ?> bytes</td>
                <td style="color:var(--tx3);font-size:12px;"><?php echo date('d M Y H:i:s',$bk['mtime']); ?></td>
                <td>
                    <form method="POST" onsubmit="return confirm('Restore from <?php echo htmlspecialchars($bn); ?>? This overwrites return_cheques.php.')">
                        <input type="hidden" name="action" value="restore">
                        <input type="hidden" name="backup_path" value="<?php echo htmlspecialchars($bk['path']); ?>">
                        <button type="submit" class="bds"><i class="fa-solid fa-rotate-left"></i> Restore</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="be"><i class="fa-solid fa-box-open" style="font-size:26px;display:block;margin-bottom:10px;opacity:.3;"></i>No backups yet — one is created automatically on each operation.</div>
        <?php endif; ?>
    </div>

    <div class="tp" id="tab-how">
        <div style="padding:18px;display:grid;gap:14px;">
            <div class="ib2">
                <div class="ib2-t"><i class="fa-solid fa-plus-circle" style="color:var(--vi);"></i> Applying the patch</div>
                <ol><li>Ensure <code>save_cheque_issue.php</code> is in the same directory.</li><li>All pre-flight checks should be green.</li><li>Click <strong>Apply Patch</strong>, confirm. A <code>.orig_</code> backup is created automatically.</li><li>Visit <code>return_cheques.php</code> — you'll see new <em>Issue Cheques</em> and <em>Issue History</em> buttons.</li></ol>
            </div>
            <div class="ib2">
                <div class="ib2-t"><i class="fa-solid fa-rotate-left" style="color:var(--rd);"></i> Reversing the patch</div>
                <ol><li>Click <strong>Reverse Patch</strong> and confirm.</li><li>The patcher finds and removes each added block by string matching.</li><li>A <code>.patched_</code> backup is saved before removal.</li><li>If reversal fails, use the <strong>Backups</strong> tab to restore from a <code>.orig_</code> backup.</li></ol>
            </div>
            <div class="ib2">
                <div class="ib2-t"><i class="fa-solid fa-database" style="color:var(--gn);"></i> Database</div>
                <p>Two tables are created automatically on first use: <code>cheque_issues</code> and <code>cheque_issue_items</code>. Reversing the PHP patch does <strong>not</strong> drop these tables — your data is safe.</p>
            </div>
            <div class="ib2">
                <div class="ib2-t"><i class="fa-solid fa-shield-halved" style="color:var(--am);"></i> Security</div>
                <p>This page performs no <code>exec()</code> or shell calls — it's pure PHP string operations. Set <code>PAGE_PASS</code> at the top of this file to password-protect it, and delete or move it once patching is done.</p>
            </div>
        </div>
    </div>
</div>

<div style="text-align:center;padding:18px 0 8px;font-size:11px;color:var(--tx3);">
    Patch Manager · Pure PHP context-matching applicator · No shell commands · <a href="<?php echo $_SERVER['PHP_SELF']; ?>" style="color:var(--tx3);">Refresh</a>
</div>

</div>

<!-- APPLY CONFIRM -->
<div class="co" id="confOverlay">
    <div class="cb2" id="confApply" style="display:none;">
        <div class="ch2 a"><i class="fa-solid fa-code-merge"></i><span>Apply Patch</span></div>
        <div class="cb2-b">
            <p>Add the <strong>cheque issue feature</strong> to:</p>
            <div class="fc"><?php echo htmlspecialchars(TARGET_FILE); ?></div>
            <p style="margin-top:10px;">Will add:</p>
            <ul><li>Issue persons AJAX handler</li><li>Issue Cheques &amp; History buttons in page header</li><li>Issue drawer, history drawer, detail modal, JS (~860 lines)</li></ul>
            <p style="margin-top:10px;font-size:12px;color:var(--tx3);">A <code>.orig_</code> timestamped backup is saved before writing.</p>
        </div>
        <div class="cb2-f">
            <button class="bc" onclick="closeConf()"><i class="fa-solid fa-xmark"></i> Cancel</button>
            <form method="POST" style="display:inline;"><input type="hidden" name="action" value="apply">
            <button type="submit" class="btn ba" style="padding:9px 18px;"><i class="fa-solid fa-code-merge"></i> Yes, Apply</button></form>
        </div>
    </div>
    <div class="cb2" id="confReverse" style="display:none;">
        <div class="ch2 r"><i class="fa-solid fa-rotate-left"></i><span>Reverse Patch</span></div>
        <div class="cb2-b">
            <p><strong>Remove the cheque issue feature</strong> from:</p>
            <div class="fc"><?php echo htmlspecialchars(TARGET_FILE); ?></div>
            <p style="margin-top:10px;">Will remove:</p>
            <ul><li>Issue persons AJAX handler</li><li>Issue Cheques &amp; History buttons</li><li>All drawers, modals, and JS</li></ul>
            <p style="margin-top:10px;font-size:12px;color:var(--tx3);">A <code>.patched_</code> backup of current version is saved first.</p>
        </div>
        <div class="cb2-f">
            <button class="bc" onclick="closeConf()"><i class="fa-solid fa-xmark"></i> Cancel</button>
            <form method="POST" style="display:inline;"><input type="hidden" name="action" value="reverse">
            <button type="submit" class="btn br" style="padding:9px 18px;"><i class="fa-solid fa-rotate-left"></i> Yes, Reverse</button></form>
        </div>
    </div>
</div>

<script>
function swTab(n,btn){
    document.querySelectorAll('.tb').forEach(b=>b.classList.remove('active'));
    document.querySelectorAll('.tp').forEach(p=>p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab-'+n).classList.add('active');
}
function openConf(t){
    document.getElementById('confOverlay').classList.add('open');
    document.getElementById('confApply').style.display   = t==='apply'  ?'':'none';
    document.getElementById('confReverse').style.display = t==='reverse'?'':'none';
    document.body.style.overflow='hidden';
}
function closeConf(){document.getElementById('confOverlay').classList.remove('open');document.body.style.overflow='';}
document.getElementById('confOverlay').addEventListener('click',function(e){if(e.target===this)closeConf();});
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeConf();});
document.addEventListener('DOMContentLoaded',()=>{const m=document.querySelector('.mb');if(m)m.scrollIntoView({behavior:'smooth',block:'center'});});
</script>
</body>
</html>

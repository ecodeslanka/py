<?php
/**
 * patch.php — CRN Modal Fix Patcher for return_cheques.php
 * Applies or reverses the "JSON-in-onclick attribute" fix.
 *
 * PATCH: Replaces inline JSON onclick with Map-based lookup
 * REVERSE: Restores original inline JSON onclick approach
 */

$TARGET_FILE = 'return_cheques.php';
$BACKUP_FILE = 'return_cheques.php.bak';

/* ══════════════════════════════════════════════════════
   PATCH DEFINITIONS
══════════════════════════════════════════════════════ */

// ── PATCH 1: Add CRN_ROW_DATA Map declaration after BANKS constant ──
$PATCH1_FIND = "const BANKS = <?php echo json_encode(\$banks_list); ?>;";
$PATCH1_REPLACE = "const BANKS = <?php echo json_encode(\$banks_list); ?>;

const CRN_ROW_DATA = new Map();";

// ── PATCH 2: Replace crnData JSON stringify + onclick with Map storage ──
// Find the crnData block and the onclick line inside renderRows
$PATCH2_FIND = <<<'FIND'
        const crnData=JSON.stringify({
            crn_no:row.crn_no||'',crn_return_reason:row.crn_return_reason||'',
            crn_return_code:row.crn_return_code||'',crn_cheque_status:row.crn_cheque_status||'',
            crn_return_remark:row.crn_return_remark||'',crn_collecting_bank:row.crn_collecting_bank||'',
            crn_collecting_branch:row.crn_collecting_branch||'',crn_date_of_return:row.crn_date_of_return||'',
            crn_file_path:row.crn_file_path||'',crn_uploaded_by:row.crn_uploaded_by||'',
            crn_uploaded_at:row.crn_uploaded_at||'',
        });
FIND;

$PATCH2_REPLACE = <<<'REPLACE'
        CRN_ROW_DATA.set(id, {
            crn_no: row.crn_no||'',
            crn_return_reason: row.crn_return_reason||'',
            crn_return_code: row.crn_return_code||'',
            crn_cheque_status: row.crn_cheque_status||'',
            crn_return_remark: row.crn_return_remark||'',
            crn_collecting_bank: row.crn_collecting_bank||'',
            crn_collecting_branch: row.crn_collecting_branch||'',
            crn_date_of_return: row.crn_date_of_return||'',
            crn_file_path: row.crn_file_path||'',
            crn_uploaded_by: row.crn_uploaded_by||'',
            crn_uploaded_at: row.crn_uploaded_at||'',
        });
REPLACE;

// ── PATCH 3: Replace onclick attribute in CRN button row ──
$PATCH3_FIND = "              onclick='openCrnModal(\${id},\"\${esc(row.cheque_no||'')}\",\"\${esc(row.bank_name||row.bank_code||'')}\",\${crnData.replace(/'/g,\"&#39;\")})'>\${crnBtnLabel}</button>";
$PATCH3_REPLACE = '              onclick="openCrnModal(${id})">${crnBtnLabel}</button>';

// ── PATCH 4: Replace openCrnModal function signature ──
$PATCH4_FIND = <<<'FIND'
function openCrnModal(chequeId,chequeNo,bankName,existingData){
    CRN_CHEQUE_ID=chequeId;CRN_FILE_PATHS=[];CRN_IMAGE_FILES=[];
    document.getElementById('crnModalSubtitle').textContent='Cheque: '+chequeNo+' — '+bankName;
    const hasCrn=existingData&&existingData.crn_no;
FIND;

$PATCH4_REPLACE = <<<'REPLACE'
function openCrnModal(chequeId){
    CRN_CHEQUE_ID=chequeId;CRN_FILE_PATHS=[];CRN_IMAGE_FILES=[];
    const row=document.getElementById('row-'+chequeId);
    const chequeNo=row?.querySelector('.mono')?.textContent?.trim()||'';
    const bankName=row?.querySelectorAll('td')[6]?.querySelector('div')?.textContent?.trim()||'';
    document.getElementById('crnModalSubtitle').textContent='Cheque: '+chequeNo+' — '+bankName;
    const existingData=CRN_ROW_DATA.get(chequeId)||{};
    const hasCrn=existingData&&existingData.crn_no;
REPLACE;

// ── PATCH 5: Update CRN_ROW_DATA after saveCrnDetails ──
$PATCH5_FIND = "        updateCrnCell(CRN_CHEQUE_ID,crn_no,d.is_representable,return_code,return_reason,cheque_status);";
$PATCH5_REPLACE = <<<'REPLACE'
        updateCrnCell(CRN_CHEQUE_ID,crn_no,d.is_representable,return_code,return_reason,cheque_status);
        CRN_ROW_DATA.set(CRN_CHEQUE_ID,{
            crn_no,crn_return_reason:return_reason,crn_return_code:return_code,
            crn_cheque_status:cheque_status,crn_return_remark:return_remark,
            crn_collecting_bank:collecting_bank,crn_collecting_branch:collecting_branch,
            crn_date_of_return:date_of_return,
            crn_file_path:JSON.stringify(CRN_FILE_PATHS),
            crn_uploaded_by:'You (just now)',crn_uploaded_at:new Date().toLocaleString('en-GB'),
        });
REPLACE;

// ── PATCH 6: Update CRN_ROW_DATA after removeCrnDetails ──
$PATCH6_FIND = "        showToast('CRN details removed ✓','ok');
        closeCrnModal();";
$PATCH6_REPLACE = "        CRN_ROW_DATA.set(CRN_CHEQUE_ID,{});
        showToast('CRN details removed ✓','ok');
        closeCrnModal();";

// Collect all patches in order
$PATCHES = [
    ['id'=>1,'desc'=>'Add CRN_ROW_DATA Map declaration after BANKS constant',   'find'=>$PATCH1_FIND,'replace'=>$PATCH1_REPLACE],
    ['id'=>2,'desc'=>'Replace JSON.stringify crnData block with Map.set()',       'find'=>$PATCH2_FIND,'replace'=>$PATCH2_REPLACE],
    ['id'=>3,'desc'=>'Simplify CRN button onclick to openCrnModal(id) only',     'find'=>$PATCH3_FIND,'replace'=>$PATCH3_REPLACE],
    ['id'=>4,'desc'=>'Update openCrnModal() to use Map lookup instead of args',   'find'=>$PATCH4_FIND,'replace'=>$PATCH4_REPLACE],
    ['id'=>5,'desc'=>'Refresh CRN_ROW_DATA after saving CRN details',            'find'=>$PATCH5_FIND,'replace'=>$PATCH5_REPLACE],
    ['id'=>6,'desc'=>'Clear CRN_ROW_DATA entry after removing CRN',              'find'=>$PATCH6_FIND,'replace'=>$PATCH6_REPLACE],
];

/* ══════════════════════════════════════════════════════
   ACTION HANDLERS
══════════════════════════════════════════════════════ */
$action  = $_POST['action'] ?? '';
$results = [];
$status  = '';

function read_file($path) {
    if (!file_exists($path)) return false;
    return file_get_contents($path);
}

function write_file($path, $content) {
    return file_put_contents($path, $content) !== false;
}

function count_occurrences($haystack, $needle) {
    return substr_count($haystack, $needle);
}

// ── CHECK PATCH STATUS ──
function check_patch_status($content, $patches) {
    $results = [];
    foreach ($patches as $p) {
        $find_present    = count_occurrences($content, $p['find'])    > 0;
        $replace_present = count_occurrences($content, $p['replace']) > 0;
        if ($replace_present && !$find_present) {
            $results[$p['id']] = 'applied';
        } elseif ($find_present && !$replace_present) {
            $results[$p['id']] = 'not_applied';
        } elseif ($find_present && $replace_present) {
            $results[$p['id']] = 'conflict';
        } else {
            $results[$p['id']] = 'not_found';
        }
    }
    return $results;
}

// ── APPLY PATCHES ──
if ($action === 'apply') {
    $content = read_file($TARGET_FILE);
    if ($content === false) {
        $status = 'error';
        $results[] = ['type'=>'error','msg'=>"Cannot read $TARGET_FILE — file not found in this directory."];
    } else {
        // Create backup
        if (!file_exists($BACKUP_FILE)) {
            write_file($BACKUP_FILE, $content);
        }
        $applied = 0; $skipped = 0; $failed = 0;
        foreach ($PATCHES as $p) {
            $cnt = count_occurrences($content, $p['find']);
            if ($cnt === 0) {
                // Check if already applied
                if (count_occurrences($content, $p['replace']) > 0) {
                    $results[] = ['type'=>'skip','msg'=>"Patch #{$p['id']} already applied — skipped."];
                    $skipped++;
                } else {
                    $results[] = ['type'=>'warn','msg'=>"Patch #{$p['id']} target not found — could not apply. ({$p['desc']})"];
                    $failed++;
                }
            } elseif ($cnt > 1) {
                $results[] = ['type'=>'warn','msg'=>"Patch #{$p['id']} target found {$cnt}x — ambiguous, skipped."];
                $failed++;
            } else {
                $content = str_replace($p['find'], $p['replace'], $content);
                $results[] = ['type'=>'ok','msg'=>"Patch #{$p['id']} applied: {$p['desc']}"];
                $applied++;
            }
        }
        if (write_file($TARGET_FILE, $content)) {
            $status = $failed > 0 ? 'partial' : 'success';
        } else {
            $status = 'error';
            $results[] = ['type'=>'error','msg'=>"Failed to write $TARGET_FILE — check file permissions."];
        }
    }
}

// ── REVERSE PATCHES ──
if ($action === 'reverse') {
    $content = read_file($TARGET_FILE);
    if ($content === false) {
        $status = 'error';
        $results[] = ['type'=>'error','msg'=>"Cannot read $TARGET_FILE — file not found."];
    } else {
        // Create backup before reversing too
        write_file('return_cheques.php.reverse_bak', $content);
        $reversed = 0; $skipped = 0; $failed = 0;
        foreach ($PATCHES as $p) {
            $cnt = count_occurrences($content, $p['replace']);
            if ($cnt === 0) {
                if (count_occurrences($content, $p['find']) > 0) {
                    $results[] = ['type'=>'skip','msg'=>"Patch #{$p['id']} not applied — nothing to reverse."];
                    $skipped++;
                } else {
                    $results[] = ['type'=>'warn','msg'=>"Patch #{$p['id']} reverse target not found — skipped."];
                    $failed++;
                }
            } elseif ($cnt > 1) {
                $results[] = ['type'=>'warn','msg'=>"Patch #{$p['id']} replacement found {$cnt}x — ambiguous, skipped."];
                $failed++;
            } else {
                $content = str_replace($p['replace'], $p['find'], $content);
                $results[] = ['type'=>'ok','msg'=>"Patch #{$p['id']} reversed: {$p['desc']}"];
                $reversed++;
            }
        }
        if (write_file($TARGET_FILE, $content)) {
            $status = $failed > 0 ? 'partial' : 'reversed';
        } else {
            $status = 'error';
            $results[] = ['type'=>'error','msg'=>"Failed to write $TARGET_FILE — check file permissions."];
        }
    }
}

// ── RESTORE FROM BACKUP ──
if ($action === 'restore') {
    $backup = read_file($BACKUP_FILE);
    if ($backup === false) {
        $status = 'error';
        $results[] = ['type'=>'error','msg'=>"Backup file $BACKUP_FILE not found."];
    } else {
        if (write_file($TARGET_FILE, $backup)) {
            $status = 'restored';
            $results[] = ['type'=>'ok','msg'=>"$TARGET_FILE restored from $BACKUP_FILE successfully."];
        } else {
            $status = 'error';
            $results[] = ['type'=>'error','msg'=>"Failed to write $TARGET_FILE — check permissions."];
        }
    }
}

// ── COMPUTE CURRENT STATUS ──
$file_exists   = file_exists($TARGET_FILE);
$backup_exists = file_exists($BACKUP_FILE);
$patch_status  = [];
if ($file_exists) {
    $current_content = read_file($TARGET_FILE);
    $patch_status    = check_patch_status($current_content, $PATCHES);
}
$all_applied  = !empty($patch_status) && !in_array('not_applied', $patch_status) && !in_array('not_found', $patch_status);
$none_applied = !empty($patch_status) && !in_array('applied', $patch_status);
$file_size    = $file_exists ? number_format(filesize($TARGET_FILE)/1024, 1).'KB' : '—';
$file_mtime   = $file_exists ? date('d M Y H:i:s', filemtime($TARGET_FILE)) : '—';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CRN Modal Fix — Patcher</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700&family=Syne:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0d0f14;
  --surface:#13161e;
  --surface2:#1a1e28;
  --surface3:#21263300;
  --border:#2a2f3e;
  --border2:#353c50;
  --text:#e8eaf0;
  --text2:#8b92a8;
  --text3:#555e75;
  --red:#ef4444;
  --red-dim:#7f1d1d;
  --red-bg:#1f0a0a;
  --green:#22c55e;
  --green-dim:#166534;
  --green-bg:#0a1f0f;
  --amber:#f59e0b;
  --amber-dim:#92400e;
  --amber-bg:#1f160a;
  --blue:#3b82f6;
  --blue-dim:#1e3a8a;
  --blue-bg:#0a0f1f;
  --accent:#6366f1;
  --accent2:#818cf8;
  --mono:'JetBrains Mono',monospace;
  --sans:'Syne',sans-serif;
}
html{min-height:100%;background:var(--bg)}
body{
  font-family:var(--sans);
  color:var(--text);
  background:var(--bg);
  min-height:100vh;
  padding:40px 20px 80px;
  background-image:
    radial-gradient(ellipse 60% 40% at 70% 10%, rgba(99,102,241,.08) 0%, transparent 70%),
    radial-gradient(ellipse 40% 30% at 10% 80%, rgba(239,68,68,.05) 0%, transparent 60%);
}
.wrapper{max-width:820px;margin:0 auto}

/* ── Header ── */
.hdr{margin-bottom:36px}
.hdr-badge{
  display:inline-flex;align-items:center;gap:8px;
  background:rgba(99,102,241,.12);border:1px solid rgba(99,102,241,.3);
  border-radius:20px;padding:4px 14px;font-size:11px;font-weight:600;
  color:var(--accent2);letter-spacing:.08em;text-transform:uppercase;margin-bottom:16px;
}
.hdr h1{
  font-size:32px;font-weight:800;color:var(--text);line-height:1.1;margin-bottom:8px;
  background:linear-gradient(135deg,#e8eaf0 30%,#818cf8 100%);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;
}
.hdr p{font-size:14px;color:var(--text2);line-height:1.6;max-width:560px}
.hdr-meta{display:flex;align-items:center;gap:14px;margin-top:14px;flex-wrap:wrap}
.meta-chip{
  display:inline-flex;align-items:center;gap:6px;font-family:var(--mono);
  font-size:11px;color:var(--text3);border:1px solid var(--border);
  border-radius:6px;padding:4px 10px;background:var(--surface);
}
.meta-chip .dot{width:7px;height:7px;border-radius:50%;}
.dot-ok{background:var(--green)}
.dot-warn{background:var(--amber)}
.dot-err{background:var(--red)}
.dot-none{background:var(--text3)}

/* ── Status card ── */
.status-card{
  background:var(--surface);border:1px solid var(--border);border-radius:14px;
  padding:22px 24px;margin-bottom:24px;
}
.status-card-header{
  display:flex;align-items:center;justify-content:space-between;
  margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid var(--border);
}
.status-card-title{font-size:13px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.07em;display:flex;align-items:center;gap:8px}
.file-info{font-family:var(--mono);font-size:11px;color:var(--text3)}

.patch-grid{display:grid;gap:8px}
.patch-row{
  display:grid;grid-template-columns:32px 1fr 90px;
  align-items:center;gap:12px;
  padding:10px 12px;border-radius:8px;background:var(--surface2);border:1px solid var(--border);
  transition:border-color .2s;
}
.patch-row:hover{border-color:var(--border2)}
.patch-num{
  width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;
  font-family:var(--mono);font-size:11px;font-weight:700;
  background:var(--surface);border:1px solid var(--border);color:var(--text3);flex-shrink:0;
}
.patch-desc{font-size:12.5px;color:var(--text2);line-height:1.4}
.patch-state{
  display:inline-flex;align-items:center;justify-content:center;gap:5px;
  padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap;
  letter-spacing:.03em;
}
.ps-applied{background:rgba(34,197,94,.12);color:var(--green);border:1px solid rgba(34,197,94,.25)}
.ps-not_applied{background:rgba(239,68,68,.1);color:var(--red);border:1px solid rgba(239,68,68,.2)}
.ps-conflict{background:rgba(245,158,11,.1);color:var(--amber);border:1px solid rgba(245,158,11,.2)}
.ps-not_found{background:rgba(139,146,168,.1);color:var(--text3);border:1px solid var(--border)}
.ps-missing{background:rgba(139,146,168,.08);color:var(--text3);border:1px solid var(--border)}

/* ── Overall status banner ── */
.banner{
  display:flex;align-items:center;gap:14px;
  border-radius:10px;padding:14px 18px;margin-bottom:24px;border-width:1.5px;border-style:solid;
}
.banner-icon{font-size:22px;flex-shrink:0}
.banner-title{font-size:14px;font-weight:700;margin-bottom:3px}
.banner-sub{font-size:12px;opacity:.8;line-height:1.5}
.banner-success{background:var(--green-bg);border-color:rgba(34,197,94,.3)}
.banner-success .banner-title{color:var(--green)}
.banner-error{background:var(--red-bg);border-color:rgba(239,68,68,.3)}
.banner-error .banner-title{color:var(--red)}
.banner-partial{background:var(--amber-bg);border-color:rgba(245,158,11,.3)}
.banner-partial .banner-title{color:var(--amber)}
.banner-info{background:var(--blue-bg);border-color:rgba(59,130,246,.3)}
.banner-info .banner-title{color:var(--blue)}
.banner-reversed{background:rgba(99,102,241,.08);border-color:rgba(99,102,241,.3)}
.banner-reversed .banner-title{color:var(--accent2)}

/* ── Result log ── */
.result-log{
  background:var(--surface2);border:1px solid var(--border);border-radius:10px;
  overflow:hidden;margin-bottom:24px;
}
.log-header{
  padding:10px 16px;background:var(--surface);border-bottom:1px solid var(--border);
  font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.07em;
  display:flex;align-items:center;gap:7px;
}
.log-entry{
  display:flex;align-items:flex-start;gap:12px;
  padding:9px 16px;border-bottom:1px solid var(--border);font-size:12.5px;line-height:1.5;
}
.log-entry:last-child{border-bottom:none}
.log-ok .log-icon{color:var(--green)}
.log-skip .log-icon{color:var(--text3)}
.log-warn .log-icon{color:var(--amber)}
.log-error .log-icon{color:var(--red)}
.log-ok .log-msg{color:var(--text)}
.log-skip .log-msg{color:var(--text3)}
.log-warn .log-msg{color:var(--amber)}
.log-error .log-msg{color:var(--red)}
.log-icon{font-size:13px;margin-top:1px;flex-shrink:0;font-style:normal}

/* ── Action buttons ── */
.action-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:32px}
@media(max-width:580px){.action-grid{grid-template-columns:1fr}}
.action-card{
  background:var(--surface);border:1px solid var(--border);border-radius:12px;
  padding:20px;display:flex;flex-direction:column;gap:12px;
  transition:border-color .2s,box-shadow .2s;
}
.action-card:hover{border-color:var(--border2);box-shadow:0 4px 24px rgba(0,0,0,.3)}
.ac-icon{
  width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;
  font-size:18px;margin-bottom:2px;
}
.ac-icon-apply{background:rgba(99,102,241,.15);color:var(--accent2)}
.ac-icon-reverse{background:rgba(245,158,11,.12);color:var(--amber)}
.ac-icon-restore{background:rgba(59,130,246,.12);color:var(--blue)}
.ac-title{font-size:14px;font-weight:700;color:var(--text);margin-bottom:4px}
.ac-desc{font-size:12px;color:var(--text2);line-height:1.5;flex:1}
.btn{
  display:inline-flex;align-items:center;justify-content:center;gap:7px;
  width:100%;padding:10px 16px;border-radius:8px;font-family:var(--sans);
  font-size:13px;font-weight:700;cursor:pointer;border:none;
  transition:all .2s;letter-spacing:.02em;
}
.btn:active{transform:scale(.98)}
.btn-apply{
  background:linear-gradient(135deg,#4f46e5,#6366f1);color:#fff;
  box-shadow:0 2px 12px rgba(99,102,241,.3);
}
.btn-apply:hover{box-shadow:0 4px 20px rgba(99,102,241,.5);filter:brightness(1.08)}
.btn-apply:disabled{opacity:.4;cursor:not-allowed;box-shadow:none;filter:none}
.btn-reverse{
  background:rgba(245,158,11,.12);color:var(--amber);
  border:1px solid rgba(245,158,11,.3);
}
.btn-reverse:hover{background:rgba(245,158,11,.2);border-color:rgba(245,158,11,.5)}
.btn-reverse:disabled{opacity:.35;cursor:not-allowed}
.btn-restore{
  background:rgba(59,130,246,.1);color:var(--blue);
  border:1px solid rgba(59,130,246,.25);
}
.btn-restore:hover{background:rgba(59,130,246,.18);border-color:rgba(59,130,246,.45)}
.btn-restore:disabled{opacity:.35;cursor:not-allowed}

/* ── What this patch does ── */
.info-card{
  background:var(--surface);border:1px solid var(--border);border-radius:12px;
  overflow:hidden;
}
.info-header{
  padding:14px 20px;background:var(--surface2);border-bottom:1px solid var(--border);
  font-size:12px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.07em;
  display:flex;align-items:center;gap:8px;
}
.info-body{padding:20px}
.change-list{display:grid;gap:10px}
.change-item{
  display:flex;gap:14px;padding:12px 14px;
  background:var(--surface2);border-radius:8px;border:1px solid var(--border);
}
.change-num{
  width:24px;height:24px;border-radius:6px;background:rgba(99,102,241,.15);
  color:var(--accent2);display:flex;align-items:center;justify-content:center;
  font-size:11px;font-weight:700;font-family:var(--mono);flex-shrink:0;margin-top:1px;
}
.change-text{font-size:12.5px;color:var(--text2);line-height:1.6}
.change-text strong{color:var(--text);font-weight:600}
.code-inline{
  font-family:var(--mono);font-size:11px;background:rgba(99,102,241,.12);
  color:var(--accent2);padding:1px 6px;border-radius:4px;
}

/* ── Warning ── */
.warn-box{
  display:flex;align-items:flex-start;gap:12px;
  background:var(--amber-bg);border:1px solid rgba(245,158,11,.25);
  border-radius:8px;padding:12px 16px;margin-top:16px;font-size:12px;color:var(--amber);line-height:1.6;
}
.warn-icon{font-size:16px;flex-shrink:0;margin-top:1px}

/* ── Footer ── */
.footer{
  margin-top:40px;padding-top:20px;border-top:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;
}
.footer-txt{font-size:11px;color:var(--text3);font-family:var(--mono)}
.footer-link{
  font-size:12px;color:var(--accent2);text-decoration:none;font-weight:600;
  padding:6px 14px;border:1px solid rgba(99,102,241,.3);border-radius:6px;
  transition:all .2s;display:inline-flex;align-items:center;gap:5px;
}
.footer-link:hover{background:rgba(99,102,241,.1);border-color:rgba(99,102,241,.5)}

/* ── Spinner ── */
@keyframes spin{to{transform:rotate(360deg)}}
.spin{display:inline-block;animation:spin .8s linear infinite}
</style>
</head>
<body>
<div class="wrapper">

  <!-- HEADER -->
  <div class="hdr">
    <div class="hdr-badge">⚡ Patcher v1.0</div>
    <h1>CRN Modal Fix</h1>
    <p>Patches <strong>return_cheques.php</strong> to fix the <em>JSON-in-onclick attribute</em> bug that breaks the CRN image view modal. Replaces inline JSON with a safe JavaScript <code>Map</code> lookup.</p>
    <div class="hdr-meta">
      <?php if (!$file_exists): ?>
        <span class="meta-chip"><span class="dot dot-err"></span><?= htmlspecialchars($TARGET_FILE) ?> — NOT FOUND</span>
      <?php else: ?>
        <span class="meta-chip"><span class="dot dot-ok"></span><?= htmlspecialchars($TARGET_FILE) ?></span>
        <span class="meta-chip">📦 <?= $file_size ?></span>
        <span class="meta-chip">🕐 <?= $file_mtime ?></span>
        <?php if ($backup_exists): ?>
          <span class="meta-chip"><span class="dot dot-ok"></span>Backup exists</span>
        <?php else: ?>
          <span class="meta-chip"><span class="dot dot-none"></span>No backup yet</span>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- ACTION RESULT BANNER -->
  <?php if ($action && !empty($results)): ?>
    <?php
      if ($status === 'success')   { $bclass='banner-success'; $bicon='✅'; $btitle='All patches applied successfully!'; $bsub='The CRN modal fix is now active. Test by clicking a CRN button in the returned cheques table.'; }
      elseif ($status === 'reversed') { $bclass='banner-reversed'; $bicon='↩️'; $btitle='All patches reversed successfully!'; $bsub='return_cheques.php has been restored to its pre-patch state.'; }
      elseif ($status === 'restored') { $bclass='banner-info'; $bicon='💾'; $btitle='File restored from backup!'; $bsub='return_cheques.php has been overwritten with '.htmlspecialchars($BACKUP_FILE).'.'; }
      elseif ($status === 'partial') { $bclass='banner-partial'; $bicon='⚠️'; $btitle='Partial — some patches could not be applied.'; $bsub='Review the log below. The file may have already been modified or the patches are in a different format.'; }
      elseif ($status === 'error')  { $bclass='banner-error'; $bicon='❌'; $btitle='Error occurred.'; $bsub='See log below for details.'; }
      else { $bclass='banner-info'; $bicon='ℹ️'; $btitle='Done.'; $bsub=''; }
    ?>
    <div class="banner <?= $bclass ?>">
      <span class="banner-icon"><?= $bicon ?></span>
      <div>
        <div class="banner-title"><?= $btitle ?></div>
        <?php if ($bsub): ?><div class="banner-sub"><?= htmlspecialchars($bsub) ?></div><?php endif; ?>
      </div>
    </div>

    <!-- LOG -->
    <div class="result-log">
      <div class="log-header">📋 Operation Log — <?= date('H:i:s') ?></div>
      <?php foreach ($results as $r): ?>
        <?php
          $lcls = 'log-'.$r['type'];
          $icon = ['ok'=>'✓','skip'=>'—','warn'=>'⚠','error'=>'✗'][$r['type']] ?? '·';
        ?>
        <div class="log-entry <?= $lcls ?>">
          <em class="log-icon"><?= $icon ?></em>
          <span class="log-msg"><?= htmlspecialchars($r['msg']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- CURRENT PATCH STATUS -->
  <?php if ($file_exists): ?>
  <div class="status-card">
    <div class="status-card-header">
      <span class="status-card-title">📊 Patch Status</span>
      <span class="file-info"><?= htmlspecialchars($TARGET_FILE) ?> · <?= $file_size ?></span>
    </div>
    <div class="patch-grid">
      <?php foreach ($PATCHES as $p): ?>
        <?php
          $pst  = $patch_status[$p['id']] ?? 'missing';
          $labels = ['applied'=>'✓ Applied','not_applied'=>'✗ Not Applied','conflict'=>'⚡ Conflict','not_found'=>'? Not Found','missing'=>'– N/A'];
          $classes = ['applied'=>'ps-applied','not_applied'=>'ps-not_applied','conflict'=>'ps-conflict','not_found'=>'ps-not_found','missing'=>'ps-missing'];
        ?>
        <div class="patch-row">
          <div class="patch-num">#<?= $p['id'] ?></div>
          <div class="patch-desc"><?= htmlspecialchars($p['desc']) ?></div>
          <span class="patch-state <?= $classes[$pst] ?? 'ps-missing' ?>"><?= $labels[$pst] ?? $pst ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ACTION BUTTONS -->
  <div class="action-grid">

    <!-- APPLY -->
    <div class="action-card">
      <div>
        <div class="ac-icon ac-icon-apply">⚡</div>
        <div class="ac-title">Apply Patch</div>
        <div class="ac-desc">Apply all 6 changes to fix the CRN modal bug. A backup is created automatically before patching.</div>
      </div>
      <form method="post">
        <input type="hidden" name="action" value="apply">
        <button type="submit" class="btn btn-apply"
          <?= (!$file_exists || $all_applied) ? 'disabled' : '' ?>>
          <?php if (!$file_exists): ?>
            ✗ File Missing
          <?php elseif ($all_applied): ?>
            ✓ Already Applied
          <?php else: ?>
            ⚡ Apply Patch
          <?php endif; ?>
        </button>
      </form>
    </div>

    <!-- REVERSE -->
    <div class="action-card">
      <div>
        <div class="ac-icon ac-icon-reverse">↩</div>
        <div class="ac-title">Reverse Patch</div>
        <div class="ac-desc">Undo the patch — restore original inline JSON onclick attribute code. Saves a reverse backup first.</div>
      </div>
      <form method="post">
        <input type="hidden" name="action" value="reverse">
        <button type="submit" class="btn btn-reverse"
          <?= (!$file_exists || $none_applied) ? 'disabled' : '' ?>>
          <?php if ($none_applied): ?>
            — Not Applied
          <?php else: ?>
            ↩ Reverse Patch
          <?php endif; ?>
        </button>
      </form>
    </div>

    <!-- RESTORE BACKUP -->
    <div class="action-card">
      <div>
        <div class="ac-icon ac-icon-restore">💾</div>
        <div class="ac-title">Restore Backup</div>
        <div class="ac-desc">Overwrite <code>return_cheques.php</code> with the original backup <code>.bak</code> file, ignoring patch state.</div>
      </div>
      <form method="post" onsubmit="return confirm('Restore return_cheques.php from backup? Current file will be overwritten.')">
        <input type="hidden" name="action" value="restore">
        <button type="submit" class="btn btn-restore" <?= !$backup_exists ? 'disabled' : '' ?>>
          <?= $backup_exists ? '💾 Restore from Backup' : '— No Backup Found' ?>
        </button>
      </form>
    </div>

  </div>

  <!-- WHAT THIS PATCH DOES -->
  <div class="info-card">
    <div class="info-header">🔧 What This Patch Changes</div>
    <div class="info-body">
      <div class="change-list">
        <div class="change-item">
          <div class="change-num">1</div>
          <div class="change-text">
            <strong>Declares</strong> <span class="code-inline">const CRN_ROW_DATA = new Map()</span> after the BANKS constant — a central store for CRN row data indexed by cheque ID.
          </div>
        </div>
        <div class="change-item">
          <div class="change-num">2</div>
          <div class="change-text">
            <strong>Replaces</strong> <span class="code-inline">JSON.stringify(crnData)</span> with <span class="code-inline">CRN_ROW_DATA.set(id, {...})</span> inside <code>renderRows()</code> — no more JSON in HTML attributes.
          </div>
        </div>
        <div class="change-item">
          <div class="change-num">3</div>
          <div class="change-text">
            <strong>Simplifies</strong> the CRN button onclick from <span class="code-inline">openCrnModal(id,"no","bank",{...})</span> to just <span class="code-inline">openCrnModal(id)</span> — eliminates all quote-escaping issues.
          </div>
        </div>
        <div class="change-item">
          <div class="change-num">4</div>
          <div class="change-text">
            <strong>Updates</strong> <span class="code-inline">openCrnModal()</span> signature to accept only <code>chequeId</code>, then retrieves cheque name/bank from the DOM and CRN data from the Map.
          </div>
        </div>
        <div class="change-item">
          <div class="change-num">5</div>
          <div class="change-text">
            <strong>Refreshes</strong> the Map entry after saving CRN details — so re-opening the modal after a save shows the updated data without a page reload.
          </div>
        </div>
        <div class="change-item">
          <div class="change-num">6</div>
          <div class="change-text">
            <strong>Clears</strong> the Map entry after removing CRN — so the modal correctly shows "No CRN uploaded" state on re-open.
          </div>
        </div>
      </div>
      <div class="warn-box">
        <em class="warn-icon">⚠</em>
        <span>
          <strong>Before patching:</strong> ensure <code><?= htmlspecialchars($TARGET_FILE) ?></code> is in the same directory as <code>patch.php</code>, or place <code>patch.php</code> in your project root. A backup is auto-created as <code><?= htmlspecialchars($BACKUP_FILE) ?></code> before the first apply.
        </span>
      </div>
    </div>
  </div>

  <!-- FOOTER -->
  <div class="footer">
    <span class="footer-txt">patch.php · CRN Modal Fix for return_cheques.php</span>
    <a href="return_cheques.php" class="footer-link">→ Open return_cheques.php</a>
  </div>

</div>
</body>
</html>
<?php
/**
 * apply_checkbox_fix.php
 * 
 * Run this script ONCE from the same directory as cheques.php.
 * It patches only the JavaScript inside cheques.php to persist
 * checkbox selections across search / filter / page re-renders.
 *
 * Usage:  php apply_checkbox_fix.php
 * 
 * A backup is saved as cheques.php.bak before any changes are made.
 */

$file = __DIR__ . '/cheques.php';
$backup = $file . '.bak';

/* ── safety ── */
if (!file_exists($file)) {
    die("❌  cheques.php not found in " . __DIR__ . "\n");
}

$src = file_get_contents($file);
if ($src === false) {
    die("❌  Cannot read cheques.php\n");
}

/* save backup */
if (!copy($file, $backup)) {
    die("❌  Cannot write backup to $backup\n");
}
echo "✅  Backup saved → cheques.php.bak\n";

$patches = [];
$applied = [];
$failed  = [];

/* ════════════════════════════════════════════════════════════════
   PATCH 1 — Add persistent Set declarations after PAGE_SIZE line
════════════════════════════════════════════════════════════════ */
$patches['persistent_sets'] = [
    'find' => 'const PAGE_SIZE=200;let currentPage=1;let currentSearch=\'\';let fetchTimer=null;let lastFetchController=null;',
    'replace' => 'const PAGE_SIZE=200;let currentPage=1;let currentSearch=\'\';let fetchTimer=null;let lastFetchController=null;
/* ── Persistent selection: survive search/filter/page re-renders ── */
const selectedToBeBankIds = new Set();
const selectedDepositIds  = new Set();'
];

/* ════════════════════════════════════════════════════════════════
   PATCH 2 — Replace updateSelCount to read from Set
════════════════════════════════════════════════════════════════ */
$patches['updateSelCount'] = [
    'find' => 'function updateSelCount(){const n=document.querySelectorAll(\'.row-select-cb:checked\').length;const b=document.getElementById(\'selCountBadge\'),btn=document.getElementById(\'markBankBtn\');if(b)b.textContent=n;if(btn)btn.disabled=(n===0);}',
    'replace' => 'function updateSelCount(){const n=selectedToBeBankIds.size;const b=document.getElementById(\'selCountBadge\'),btn=document.getElementById(\'markBankBtn\');if(b)b.textContent=n;if(btn)btn.disabled=(n===0);}'
];

/* ════════════════════════════════════════════════════════════════
   PATCH 3 — Replace updateDepCount to read from Set
════════════════════════════════════════════════════════════════ */
$patches['updateDepCount'] = [
    'find' => 'function updateDepCount(){const n=document.querySelectorAll(\'.row-dep-cb:checked\').length;const b=document.getElementById(\'depCountBadge\'),btn=document.getElementById(\'depositBtn\');if(b)b.textContent=n;if(btn)btn.disabled=(n===0);}',
    'replace' => 'function updateDepCount(){const n=selectedDepositIds.size;const b=document.getElementById(\'depCountBadge\'),btn=document.getElementById(\'depositBtn\');if(b)b.textContent=n;if(btn)btn.disabled=(n===0);}'
];

/* ════════════════════════════════════════════════════════════════
   PATCH 4 — Replace toggleSelectAll to maintain Sets
════════════════════════════════════════════════════════════════ */
$patches['toggleSelectAll'] = [
    'find' => 'function toggleSelectAll(cb){document.querySelectorAll(\'.row-select-cb,.row-dep-cb\').forEach(c=>c.checked=cb.checked);updateSelCount();updateDepCount();}',
    'replace' => 'function toggleSelectAll(cb){
    document.querySelectorAll(\'.row-select-cb\').forEach(c=>{
        c.checked=cb.checked;
        if(cb.checked) selectedToBeBankIds.add(c.dataset.id);
        else selectedToBeBankIds.delete(c.dataset.id);
    });
    document.querySelectorAll(\'.row-dep-cb\').forEach(c=>{
        c.checked=cb.checked;
        if(cb.checked) selectedDepositIds.add(c.dataset.id);
        else selectedDepositIds.delete(c.dataset.id);
    });
    updateSelCount();updateDepCount();
}
function onToBeBankCb(cb){const id=cb.dataset.id;if(cb.checked)selectedToBeBankIds.add(id);else selectedToBeBankIds.delete(id);updateSelCount();}
function onDepositCb(cb){const id=cb.dataset.id;if(cb.checked)selectedDepositIds.add(id);else selectedDepositIds.delete(id);updateDepCount();}'
];

/* ════════════════════════════════════════════════════════════════
   PATCH 5 — Checkbox onchange in renderRows html string
   to_be_bank checkbox
════════════════════════════════════════════════════════════════ */
$patches['cb_tobebank_onchange'] = [
    'find' => 'if(verified&&!is_tbb) cbHtml=`<input type="checkbox" class="chq-select-cb row-select-cb" data-id="${id}" data-type="to_be_bank" onchange="updateSelCount()" title="Select for To Be Bank">`;',
    'replace' => 'if(verified&&!is_tbb) cbHtml=`<input type="checkbox" class="chq-select-cb row-select-cb" data-id="${sid}" data-type="to_be_bank" onchange="onToBeBankCb(this)" title="Select for To Be Bank">`;'
];

/* ════════════════════════════════════════════════════════════════
   PATCH 6 — Checkbox onchange in renderRows html string
   deposit checkbox
════════════════════════════════════════════════════════════════ */
$patches['cb_deposit_onchange'] = [
    'find' => 'else if(is_tbb) cbHtml=`<input type="checkbox" class="chq-select-cb row-dep-cb" data-id="${id}" data-type="deposit" onchange="updateDepCount()" title="Select for Deposit">`;',
    'replace' => 'else if(is_tbb) cbHtml=`<input type="checkbox" class="chq-select-cb row-dep-cb" data-id="${sid}" data-type="deposit" onchange="onDepositCb(this)" title="Select for Deposit">`;'
];

/* ════════════════════════════════════════════════════════════════
   PATCH 7 — Add sid variable inside renderRows forEach
   (needed because patches 5 & 6 use ${sid} not ${id})
════════════════════════════════════════════════════════════════ */
$patches['sid_var'] = [
    'find' => 'const id=row.id;let st=(row.status||\'pending\').toLowerCase().trim();if(st===\'bounced\')st=\'returned\';',
    'replace' => 'const id=row.id;const sid=String(id);let st=(row.status||\'pending\').toLowerCase().trim();if(st===\'bounced\')st=\'returned\';'
];

/* ════════════════════════════════════════════════════════════════
   PATCH 8 — Restore checkboxes after tbody rebuild in renderRows
   Replace: const sa=...;if(sa)sa.checked=false;updateSelCount();updateDepCount();
════════════════════════════════════════════════════════════════ */
$patches['restore_checkboxes'] = [
    'find' => 'tbody.innerHTML=html;
    buildPager(pages,total,offset,Math.min(offset+PAGE_SIZE,total));
    const sa=document.getElementById(\'selectAll\');if(sa)sa.checked=false;updateSelCount();updateDepCount();',
    'replace' => 'tbody.innerHTML=html;
    buildPager(pages,total,offset,Math.min(offset+PAGE_SIZE,total));
    /* ── Restore persisted checkbox state after re-render ── */
    document.querySelectorAll(\'.row-select-cb\').forEach(cb=>{
        if(selectedToBeBankIds.has(cb.dataset.id)) cb.checked=true;
    });
    document.querySelectorAll(\'.row-dep-cb\').forEach(cb=>{
        if(selectedDepositIds.has(cb.dataset.id)) cb.checked=true;
    });
    const sa=document.getElementById(\'selectAll\');if(sa)sa.checked=false;
    updateSelCount();updateDepCount();'
];

/* ════════════════════════════════════════════════════════════════
   PATCH 9 — openBankDateModal read count from Set
════════════════════════════════════════════════════════════════ */
$patches['bankmodal_count'] = [
    'find' => 'function openBankDateModal(){const n=document.querySelectorAll(\'.row-select-cb:checked\').length;',
    'replace' => 'function openBankDateModal(){const n=selectedToBeBankIds.size;'
];

/* ════════════════════════════════════════════════════════════════
   PATCH 10 — confirmMarkToBeBank use Set for IDs + clear after success
════════════════════════════════════════════════════════════════ */
$patches['confirm_tobebank_ids'] = [
    'find' => 'const ids=Array.from(document.querySelectorAll(\'.row-select-cb:checked\')).map(c=>c.dataset.id).filter(Boolean);if(!ids.length){showToast(\'No cheques selected\',\'err\');return;}',
    'replace' => 'const ids=Array.from(selectedToBeBankIds);if(!ids.length){showToast(\'No cheques selected\',\'err\');return;}'
];

$patches['confirm_tobebank_clear'] = [
    'find' => 'if(data.success){closeBankDateModal();let msg=\'✓ \'+data.updated+\' cheque(s) marked as To Be Bank\';if(data.skipped>0)msg+=\' · \'+data.skipped+\' skipped\';showToast(msg,\'ok\');fetchRows();}',
    'replace' => 'if(data.success){closeBankDateModal();selectedToBeBankIds.clear();let msg=\'✓ \'+data.updated+\' cheque(s) marked as To Be Bank\';if(data.skipped>0)msg+=\' · \'+data.skipped+\' skipped\';showToast(msg,\'ok\');fetchRows();}'
];

/* ════════════════════════════════════════════════════════════════
   PATCH 11 — openDepositModal use Set for IDs
════════════════════════════════════════════════════════════════ */
$patches['deposit_modal_ids'] = [
    'find' => 'function openDepositModal(){const checked=Array.from(document.querySelectorAll(\'.row-dep-cb:checked\'));_depIds=checked.map(c=>c.dataset.id).filter(Boolean);if(!_depIds.length){showToast(\'No To Be Bank cheques selected\',\'err\');return;}_showDepositModal();}',
    'replace' => 'function openDepositModal(){_depIds=Array.from(selectedDepositIds);if(!_depIds.length){showToast(\'No To Be Bank cheques selected\',\'err\');return;}_showDepositModal();}'
];

/* ════════════════════════════════════════════════════════════════
   PATCH 12 — confirmDeposit clear Set after success
════════════════════════════════════════════════════════════════ */
$patches['confirm_deposit_clear'] = [
    'find' => 'if(data.success){closeDepositModal();let msg=\'✓ \'+data.updated+\' cheque(s) deposited\';if(data.skipped>0)msg+=\' · \'+data.skipped+\' skipped\';showToast(msg,\'ok\');fetchRows();}',
    'replace' => 'if(data.success){closeDepositModal();selectedDepositIds.clear();let msg=\'✓ \'+data.updated+\' cheque(s) deposited\';if(data.skipped>0)msg+=\' · \'+data.skipped+\' skipped\';showToast(msg,\'ok\');fetchRows();}'
];

/* ════════════════════════════════════════════════════════════════
   APPLY ALL PATCHES
════════════════════════════════════════════════════════════════ */
$out = $src;

foreach ($patches as $name => $patch) {
    $find    = $patch['find'];
    $replace = $patch['replace'];

    if (strpos($out, $find) === false) {
        $failed[] = $name;
        echo "⚠️   PATCH '$name' — search string NOT FOUND (skipped)\n";
    } else {
        $count = substr_count($out, $find);
        if ($count > 1) {
            echo "⚠️   PATCH '$name' — found $count occurrences, replacing first only\n";
        }
        $out = str_replace($find, $replace, $out);
        $applied[] = $name;
        echo "✅  PATCH '$name' applied\n";
    }
}

/* ════════════════════════════════════════════════════════════════
   WRITE OUTPUT
════════════════════════════════════════════════════════════════ */
echo "\n";

if (empty($applied)) {
    echo "❌  No patches were applied. cheques.php was NOT modified.\n";
    echo "    Possible reason: the JS code was already patched or differs from expected.\n";
    exit(1);
}

if (file_put_contents($file, $out) === false) {
    echo "❌  Failed to write cheques.php. Restore from cheques.php.bak\n";
    exit(1);
}

echo "════════════════════════════════════════════\n";
echo "  Applied : " . count($applied) . " / " . count($patches) . " patches\n";
if (!empty($failed)) {
    echo "  Skipped : " . implode(', ', $failed) . "\n";
    echo "\n  ℹ️  Skipped patches may mean the code was already patched,\n";
    echo "     or the JS was minified differently. Check cheques.php manually\n";
    echo "     for those items using the comments in this file as a guide.\n";
}
echo "  Backup  : cheques.php.bak\n";
echo "════════════════════════════════════════════\n";
echo "✅  cheques.php updated successfully!\n\n";

echo "WHAT WAS FIXED:\n";
echo "  Before: Search/filter re-rendered tbody, destroying all checkboxes.\n";
echo "          Selected IDs were lost on every keystroke.\n";
echo "  After:  selectedToBeBankIds and selectedDepositIds (JS Sets) persist\n";
echo "          across re-renders. Checkboxes are restored automatically.\n";
echo "          Badge counts and bulk action buttons read from Sets, not DOM.\n";
echo "          Sets are cleared after a successful bulk action.\n";
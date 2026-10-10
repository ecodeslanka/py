<?php
/**
 * patch_cheques_settlement.php
 * ============================
 * Place this file in the SAME folder as cheques.php and open it in browser:
 *   http://yoursite.com/patch_cheques_settlement.php
 * 
 * It will:
 *  1. Create a backup (cheques.php.bak)
 *  2. Apply all 8 settlement changes
 *  3. Show results
 * 
 * DELETE THIS FILE after patching!
 */

set_time_limit(60);
header('Content-Type: text/html; charset=utf-8');

$file = __DIR__ . '/cheques.php';
$backup = __DIR__ . '/cheques.php.bak';

echo '<html><head><title>Patch cheques.php</title>';
echo '<style>body{font-family:monospace;background:#1e1b4b;color:#e2e8f0;padding:30px;line-height:1.8}';
echo '.ok{color:#4ade80}.err{color:#f87171}.warn{color:#fbbf24}.info{color:#60a5fa}';
echo 'h1{color:#a5b4fc}h2{color:#818cf8;margin-top:20px}.box{background:#312e81;padding:16px;border-radius:10px;margin:10px 0}';
echo '</style></head><body>';
echo '<h1>🔧 Patch cheques.php — Settlement Status</h1>';

if (!file_exists($file)) {
    echo '<p class="err">❌ cheques.php not found in ' . __DIR__ . '</p></body></html>';
    exit;
}

// Backup
if (!file_exists($backup)) {
    copy($file, $backup);
    echo '<p class="ok">✅ Backup created: cheques.php.bak</p>';
} else {
    echo '<p class="warn">⚠️ Backup already exists (cheques.php.bak) — skipping backup</p>';
}

$content = file_get_contents($file);
$original_size = strlen($content);
$changes = 0;
$errors = [];

// ═══════════════════════════════════════════════════
// STEP 1: Add settlement columns to AJAX cheque_rows
// ═══════════════════════════════════════════════════
echo '<h2>Step 1/8: Add settlement columns to cheque_rows SQL</h2>';

$find1 = '               ch.cheque_front_image, ch.cheque_back_image,
               (SELECT cba.account_holder_name FROM customer_bank_accounts cba
                WHERE cba.customer_id=c.id ORDER BY cba.id LIMIT 1) AS acc_holder_name';

$replace1 = '               ch.cheque_front_image, ch.cheque_back_image,
               COALESCE(ch.return_settled, 0) AS return_settled,
               COALESCE(ch.settlement_amount, 0) AS return_settlement_amount,
               COALESCE(ch.sb_settled, 0) AS sb_settled,
               COALESCE(ch.sb_settlement_amount, 0) AS sb_settlement_amount,
               (SELECT cba.account_holder_name FROM customer_bank_accounts cba
                WHERE cba.customer_id=c.id ORDER BY cba.id LIMIT 1) AS acc_holder_name';

if (strpos($content, $find1) !== false) {
    $content = str_replace($find1, $replace1, $content);
    $changes++;
    echo '<p class="ok">✅ Added return_settled, settlement_amount, sb_settled, sb_settlement_amount to SELECT</p>';
} else if (strpos($content, 'AS return_settled') !== false) {
    echo '<p class="warn">⚠️ Already patched — skipping</p>';
} else {
    $errors[] = 'Step 1';
    echo '<p class="err">❌ Pattern not found</p>';
}

// ═══════════════════════════════════════════════════
// STEP 2: Update statusCell() JavaScript function
// ═══════════════════════════════════════════════════
echo '<h2>Step 2/8: Update statusCell() — show SB Settled / RTN Settled</h2>';

$find2 = 'function statusCell(id, st) {
    const lbl = status_labels_js[st] || st;
    const icon = status_icons_js[st] || \'fa-circle-dot\';
    return `<span class="status-badge-clickable ${st}" onclick="openStatusUpdateModal(${id},\'${st}\')" title="Click to change status"><i class="fa-solid ${icon}"></i> ${lbl}</span>`;
}';

$replace2 = 'function statusCell(id, st, row) {
    const lbl = status_labels_js[st] || st;
    const icon = status_icons_js[st] || \'fa-circle-dot\';
    const cheqAmt = parseFloat(row.total_amount || 0);
    /* ── Sent Back settlement display ── */
    if (st === \'sent_back\') {
        const sbSettled = parseInt(row.sb_settled || 0);
        const sbPaid = parseFloat(row.sb_settlement_amount || 0);
        const sbBal = Math.max(0, cheqAmt - sbPaid);
        if (sbSettled) {
            return `<div style="text-align:center;"><span class="status-badge-clickable cleared" onclick="openStatusUpdateModal(${id},\'${st}\')" title="Fully settled" style="background:#d1fae5;color:#065f46;border-color:#6ee7b7;"><i class="fa-solid fa-circle-check"></i> SB Settled</span><div style="font-size:10px;color:#065f46;margin-top:3px;">Paid: Rs.${sbPaid.toFixed(2)}</div></div>`;
        } else if (sbPaid > 0) {
            return `<div style="text-align:center;"><span class="status-badge-clickable sent_back" onclick="openStatusUpdateModal(${id},\'${st}\')" title="Click to change status"><i class="fa-solid fa-rotate-left"></i> Sent Back</span><div style="font-size:10px;color:#92400e;margin-top:3px;">Paid: Rs.${sbPaid.toFixed(2)} | Bal: Rs.${sbBal.toFixed(2)}</div></div>`;
        } else {
            return `<div style="text-align:center;"><span class="status-badge-clickable sent_back" onclick="openStatusUpdateModal(${id},\'${st}\')" title="Click to change status"><i class="fa-solid fa-rotate-left"></i> Sent Back</span><div style="font-size:10px;color:#9ca3af;margin-top:3px;">Bal: Rs.${cheqAmt.toFixed(2)}</div></div>`;
        }
    }
    /* ── Returned settlement display ── */
    if (st === \'returned\') {
        const rtnSettled = parseInt(row.return_settled || 0);
        const rtnPaid = parseFloat(row.return_settlement_amount || 0);
        const rtnBal = Math.max(0, cheqAmt - rtnPaid);
        if (rtnSettled) {
            return `<div style="text-align:center;"><span class="status-badge-clickable cleared" onclick="openStatusUpdateModal(${id},\'${st}\')" title="Fully settled" style="background:#d1fae5;color:#065f46;border-color:#6ee7b7;"><i class="fa-solid fa-circle-check"></i> RTN Settled</span><div style="font-size:10px;color:#065f46;margin-top:3px;">Paid: Rs.${rtnPaid.toFixed(2)}</div></div>`;
        } else if (rtnPaid > 0) {
            return `<div style="text-align:center;"><span class="status-badge-clickable returned" onclick="openStatusUpdateModal(${id},\'${st}\')" title="Click to change status"><i class="fa-solid fa-circle-xmark"></i> Returned</span><div style="font-size:10px;color:#92400e;margin-top:3px;">Paid: Rs.${rtnPaid.toFixed(2)} | Bal: Rs.${rtnBal.toFixed(2)}</div></div>`;
        } else {
            return `<div style="text-align:center;"><span class="status-badge-clickable returned" onclick="openStatusUpdateModal(${id},\'${st}\')" title="Click to change status"><i class="fa-solid fa-circle-xmark"></i> Returned</span><div style="font-size:10px;color:#9ca3af;margin-top:3px;">Bal: Rs.${cheqAmt.toFixed(2)}</div></div>`;
        }
    }
    return `<span class="status-badge-clickable ${st}" onclick="openStatusUpdateModal(${id},\'${st}\')" title="Click to change status"><i class="fa-solid ${icon}"></i> ${lbl}</span>`;
}';

if (strpos($content, $find2) !== false) {
    $content = str_replace($find2, $replace2, $content);
    $changes++;
    echo '<p class="ok">✅ statusCell() now shows SB Settled / RTN Settled with paid/balance</p>';
} else if (strpos($content, 'function statusCell(id, st, row)') !== false) {
    echo '<p class="warn">⚠️ Already patched — skipping</p>';
} else {
    $errors[] = 'Step 2';
    echo '<p class="err">❌ Pattern not found</p>';
}

// ═══════════════════════════════════════════════════
// STEP 3: Pass row object to statusCell call
// ═══════════════════════════════════════════════════
echo '<h2>Step 3/8: Pass row data to statusCell()</h2>';

$find3 = '<td class="tc">${statusCell(id,st)}</td>';
$replace3 = '<td class="tc">${statusCell(id,st,row)}</td>';

if (strpos($content, $find3) !== false) {
    $content = str_replace($find3, $replace3, $content);
    $changes++;
    echo '<p class="ok">✅ statusCell(id,st) → statusCell(id,st,row)</p>';
} else if (strpos($content, 'statusCell(id,st,row)') !== false) {
    echo '<p class="warn">⚠️ Already patched — skipping</p>';
} else {
    $errors[] = 'Step 3';
    echo '<p class="err">❌ Pattern not found</p>';
}

// ═══════════════════════════════════════════════════
// STEP 4: Add PHP balance queries + update in_hand
// ═══════════════════════════════════════════════════
echo '<h2>Step 4/8: Add PHP settlement balance queries</h2>';

$find4 = '$in_hand_amount = $status_totals[\'pending\'][\'amount\'] + $status_totals[\'to_be_bank\'][\'amount\'] + $status_totals[\'deposited\'][\'amount\'];
$in_hand_count  = $status_totals[\'pending\'][\'count\']  + $status_totals[\'to_be_bank\'][\'count\']  + $status_totals[\'deposited\'][\'count\'];';

$replace4 = '/* ── Calculate sent_back and returned unsettled balances ── */
$sb_bal_r = mysqli_query($conn, "SELECT
    COALESCE(SUM(CASE WHEN COALESCE(sb_settled,0)=1 THEN 0 ELSE GREATEST(total_amount - COALESCE(sb_settlement_amount,0), 0) END),0) AS sb_unsettled_bal,
    COUNT(CASE WHEN COALESCE(sb_settled,0)=0 THEN 1 END) AS sb_unsettled_cnt,
    COUNT(CASE WHEN COALESCE(sb_settled,0)=1 THEN 1 END) AS sb_settled_cnt
    FROM cheques WHERE status=\'sent_back\'");
$sb_bal_data = $sb_bal_r ? mysqli_fetch_assoc($sb_bal_r) : [\'sb_unsettled_bal\'=>0,\'sb_unsettled_cnt\'=>0,\'sb_settled_cnt\'=>0];
$sb_unsettled_balance = (float)($sb_bal_data[\'sb_unsettled_bal\'] ?? 0);

$rtn_bal_r = mysqli_query($conn, "SELECT
    COALESCE(SUM(CASE WHEN COALESCE(return_settled,0)=1 THEN 0 ELSE GREATEST(total_amount - COALESCE(settlement_amount,0), 0) END),0) AS rtn_unsettled_bal,
    COUNT(CASE WHEN COALESCE(return_settled,0)=0 THEN 1 END) AS rtn_unsettled_cnt,
    COUNT(CASE WHEN COALESCE(return_settled,0)=1 THEN 1 END) AS rtn_settled_cnt
    FROM cheques WHERE status=\'returned\' OR status=\'bounced\'");
$rtn_bal_data = $rtn_bal_r ? mysqli_fetch_assoc($rtn_bal_r) : [\'rtn_unsettled_bal\'=>0,\'rtn_unsettled_cnt\'=>0,\'rtn_settled_cnt\'=>0];
$rtn_unsettled_balance = (float)($rtn_bal_data[\'rtn_unsettled_bal\'] ?? 0);

/* Cheques in Hand = Pending + To Be Bank + Deposited + SB unsettled balance */
$in_hand_amount = $status_totals[\'pending\'][\'amount\'] + $status_totals[\'to_be_bank\'][\'amount\'] + $status_totals[\'deposited\'][\'amount\'] + $sb_unsettled_balance;
$in_hand_count  = $status_totals[\'pending\'][\'count\']  + $status_totals[\'to_be_bank\'][\'count\']  + $status_totals[\'deposited\'][\'count\'] + (int)($sb_bal_data[\'sb_unsettled_cnt\'] ?? 0);

/* Total adjusted = use balance for SB & RTN instead of full cheque amounts */
$g_total_amount_adjusted = $status_totals[\'pending\'][\'amount\'] + $status_totals[\'to_be_bank\'][\'amount\']
    + $status_totals[\'deposited\'][\'amount\'] + $status_totals[\'cleared\'][\'amount\']
    + $sb_unsettled_balance + $rtn_unsettled_balance;';

if (strpos($content, $find4) !== false) {
    $content = str_replace($find4, $replace4, $content);
    $changes++;
    echo '<p class="ok">✅ Added SB/RTN balance queries + adjusted totals</p>';
    echo '<div class="box"><span class="info">Formula:</span><br>';
    echo 'Cheques in Hand = Pending + To Be Bank + Deposited + <b>SB Balance</b><br>';
    echo 'Total Adjusted = Pending + TBB + Deposited + Cleared + <b>SB Bal</b> + <b>RTN Bal</b></div>';
} else if (strpos($content, '$sb_unsettled_balance') !== false) {
    echo '<p class="warn">⚠️ Already patched — skipping</p>';
} else {
    $errors[] = 'Step 4';
    echo '<p class="err">❌ Pattern not found</p>';
}

// ═══════════════════════════════════════════════════
// STEP 5: Total Cheques stat card — use adjusted total
// ═══════════════════════════════════════════════════
echo '<h2>Step 5/8: Total Cheques card → adjusted amount</h2>';

$find5 = 'onclick="filterByStatus(\'\')" title="All cheques"><div class="stat-label">Total Cheques</div><div class="stat-value sv-violet" style="font-size:15px;">Rs.&nbsp;<?=number_format($g_total_amount,0)?>';
$replace5 = 'onclick="filterByStatus(\'\')" title="All cheques"><div class="stat-label">Total Cheques</div><div class="stat-value sv-violet" style="font-size:15px;">Rs.&nbsp;<?=number_format($g_total_amount_adjusted,0)?>';

if (strpos($content, $find5) !== false) {
    $content = str_replace($find5, $replace5, $content);
    $changes++;
    echo '<p class="ok">✅ Total Cheques now shows adjusted amount (with SB/RTN balances)</p>';
} else if (strpos($content, '$g_total_amount_adjusted') !== false) {
    echo '<p class="warn">⚠️ Already patched — skipping</p>';
} else {
    $errors[] = 'Step 5';
    echo '<p class="err">❌ Pattern not found</p>';
}

// ═══════════════════════════════════════════════════
// STEP 6: Cheques in Hand stat card
// ═══════════════════════════════════════════════════
echo '<h2>Step 6/8: Cheques in Hand card → include SB balance</h2>';

$find6 = 'title="Cheques in Hand (Pending + To Be Bank + Deposited)">';
$replace6 = 'title="Cheques in Hand = Pending + To Be Bank + Deposited + SB Balance">';

if (strpos($content, $find6) !== false) {
    $content = str_replace($find6, $replace6, $content);
    
    // Also update the sub-text to show SB balance note
    $find6b = '<div class="stat-card-amt"><?=$in_hand_count?> cheques</div>
</div>';
    $replace6b = '<div class="stat-card-amt"><?=$in_hand_count?> items<?php if($sb_unsettled_balance > 0): ?> <span style="font-size:9px;color:#7c3aed;">(+SB Bal: Rs.<?=number_format($sb_unsettled_balance,0)?>)</span><?php endif; ?></div>
</div>';
    
    // Only replace the first occurrence (the Cheques in Hand card)
    $pos = strpos($content, $find6b);
    if ($pos !== false) {
        $content = substr_replace($content, $replace6b, $pos, strlen($find6b));
    }
    
    $changes++;
    echo '<p class="ok">✅ Cheques in Hand now includes SB unsettled balance</p>';
} else if (strpos($content, 'SB Balance') !== false) {
    echo '<p class="warn">⚠️ Already patched — skipping</p>';
} else {
    $errors[] = 'Step 6';
    echo '<p class="err">❌ Pattern not found</p>';
}

// ═══════════════════════════════════════════════════
// STEP 7: Sent Back stat card — bold = BALANCE
// ═══════════════════════════════════════════════════
echo '<h2>Step 7/8: Sent Back card → bold = unsettled balance</h2>';

$find7 = "onclick=\"filterByStatus('sent_back')\"><div class=\"stat-label\"><i class=\"fa-solid fa-rotate-left\" style=\"color:#7c3aed;\"></i> Sent Back</div><div class=\"stat-value sv-violet\" style=\"font-size:15px;\">Rs.&nbsp;<?=number_format(\$status_totals['sent_back']['amount'],0)?></div><div class=\"stat-card-amt\"><?=\$status_totals['sent_back']['count']?> cheques</div></div>";

$replace7 = "onclick=\"filterByStatus('sent_back')\"><div class=\"stat-label\"><i class=\"fa-solid fa-rotate-left\" style=\"color:#7c3aed;\"></i> Sent Back</div><div class=\"stat-value sv-violet\" style=\"font-size:15px;\">Rs.&nbsp;<?=number_format(\$sb_unsettled_balance,0)?></div><div class=\"stat-card-amt\"><?=\$status_totals['sent_back']['count']?> cheques &middot; <span style=\"color:#6b7280;font-size:9px;\">Total: Rs.<?=number_format(\$status_totals['sent_back']['amount'],0)?></span><?php if((int)(\$sb_bal_data['sb_settled_cnt']??0) > 0): ?> &middot; <span style=\"color:#16a34a;\"><?=(int)\$sb_bal_data['sb_settled_cnt']?> settled</span><?php endif; ?></div></div>";

if (strpos($content, $find7) !== false) {
    $content = str_replace($find7, $replace7, $content);
    $changes++;
    echo '<p class="ok">✅ Sent Back bold value = Rs. BALANCE (not total cheque amount)</p>';
} else if (strpos($content, '$sb_unsettled_balance,0') !== false && strpos($content, 'Sent Back</div><div class="stat-value') !== false) {
    echo '<p class="warn">⚠️ Already patched — skipping</p>';
} else {
    $errors[] = 'Step 7';
    echo '<p class="err">❌ Pattern not found — will try alternate match</p>';
    
    // Alternate: find by unique key phrase
    $alt_find = "Sent Back</div><div class=\"stat-value sv-violet\" style=\"font-size:15px;\">Rs.&nbsp;<?=number_format(\$status_totals['sent_back']['amount'],0)?>";
    $alt_replace = "Sent Back</div><div class=\"stat-value sv-violet\" style=\"font-size:15px;\">Rs.&nbsp;<?=number_format(\$sb_unsettled_balance,0)?>";
    if (strpos($content, $alt_find) !== false) {
        $content = str_replace($alt_find, $alt_replace, $content);
        
        // Also update the sub text
        $alt_find2 = "['sent_back']['count']?> cheques</div></div>";
        $alt_replace2 = "['sent_back']['count']?> cheques &middot; <span style=\"color:#6b7280;font-size:9px;\">Total: Rs.<?=number_format(\$status_totals['sent_back']['amount'],0)?></span><?php if((int)(\$sb_bal_data['sb_settled_cnt']??0) > 0): ?> &middot; <span style=\"color:#16a34a;\"><?=(int)\$sb_bal_data['sb_settled_cnt']?> settled</span><?php endif; ?></div></div>";
        $content = str_replace($alt_find2, $alt_replace2, $content);
        
        $changes++;
        array_pop($errors);
        echo '<p class="ok">✅ (Alternate match) Sent Back bold = BALANCE</p>';
    }
}

// ═══════════════════════════════════════════════════
// STEP 8: Returned stat card — bold = BALANCE
// ═══════════════════════════════════════════════════
echo '<h2>Step 8/8: Returned card → bold = unsettled balance</h2>';

$find8 = "onclick=\"filterByStatus('returned')\"><div class=\"stat-label\"><i class=\"fa-solid fa-circle-xmark\" style=\"color:#dc2626;\"></i> Returned</div><div class=\"stat-value sv-red\" style=\"font-size:15px;\">Rs.&nbsp;<?=number_format(\$status_totals['returned']['amount'],0)?></div><div class=\"stat-card-amt\"><?=\$status_totals['returned']['count']?> cheques</div></div>";

$replace8 = "onclick=\"filterByStatus('returned')\"><div class=\"stat-label\"><i class=\"fa-solid fa-circle-xmark\" style=\"color:#dc2626;\"></i> Returned</div><div class=\"stat-value sv-red\" style=\"font-size:15px;\">Rs.&nbsp;<?=number_format(\$rtn_unsettled_balance,0)?></div><div class=\"stat-card-amt\"><?=\$status_totals['returned']['count']?> cheques &middot; <span style=\"color:#6b7280;font-size:9px;\">Total: Rs.<?=number_format(\$status_totals['returned']['amount'],0)?></span><?php if((int)(\$rtn_bal_data['rtn_settled_cnt']??0) > 0): ?> &middot; <span style=\"color:#16a34a;\"><?=(int)\$rtn_bal_data['rtn_settled_cnt']?> settled</span><?php endif; ?></div></div>";

if (strpos($content, $find8) !== false) {
    $content = str_replace($find8, $replace8, $content);
    $changes++;
    echo '<p class="ok">✅ Returned bold value = Rs. BALANCE (not total cheque amount)</p>';
} else if (strpos($content, '$rtn_unsettled_balance,0') !== false && strpos($content, 'Returned</div><div class="stat-value') !== false) {
    echo '<p class="warn">⚠️ Already patched — skipping</p>';
} else {
    $errors[] = 'Step 8';
    echo '<p class="err">❌ Pattern not found — will try alternate match</p>';
    
    $alt_find = "Returned</div><div class=\"stat-value sv-red\" style=\"font-size:15px;\">Rs.&nbsp;<?=number_format(\$status_totals['returned']['amount'],0)?>";
    $alt_replace = "Returned</div><div class=\"stat-value sv-red\" style=\"font-size:15px;\">Rs.&nbsp;<?=number_format(\$rtn_unsettled_balance,0)?>";
    if (strpos($content, $alt_find) !== false) {
        $content = str_replace($alt_find, $alt_replace, $content);
        
        $alt_find2_r = "['returned']['count']?> cheques</div></div>";
        // Count occurrences to make sure we replace the right one
        $pos_r = strpos($content, $alt_find2_r);
        if ($pos_r !== false) {
            $alt_replace2_r = "['returned']['count']?> cheques &middot; <span style=\"color:#6b7280;font-size:9px;\">Total: Rs.<?=number_format(\$status_totals['returned']['amount'],0)?></span><?php if((int)(\$rtn_bal_data['rtn_settled_cnt']??0) > 0): ?> &middot; <span style=\"color:#16a34a;\"><?=(int)\$rtn_bal_data['rtn_settled_cnt']?> settled</span><?php endif; ?></div></div>";
            $content = substr_replace($content, $alt_replace2_r, $pos_r, strlen($alt_find2_r));
        }
        
        $changes++;
        array_pop($errors);
        echo '<p class="ok">✅ (Alternate match) Returned bold = BALANCE</p>';
    }
}

// ═══════════════════════════════════════════════════
// BONUS: Add data attributes to rows
// ═══════════════════════════════════════════════════
$bonus_find = 'data-mode="${esc(row.cheque_mode||\'\')}\">';
$bonus_replace = 'data-mode="${esc(row.cheque_mode||\'\')}\" data-sb-settled="${row.sb_settled||0}" data-sb-amt="${row.sb_settlement_amount||0}" data-rtn-settled="${row.return_settled||0}" data-rtn-amt="${row.return_settlement_amount||0}">';
if (strpos($content, $bonus_find) !== false && strpos($content, 'data-sb-settled') === false) {
    $content = str_replace($bonus_find, $bonus_replace, $content);
    echo '<p class="ok">✅ [Bonus] Added settlement data attributes to table rows</p>';
}

// ═══════════════════════════════════════════════════
// SAVE
// ═══════════════════════════════════════════════════
file_put_contents($file, $content);
$new_size = strlen($content);

echo '<hr style="border-color:#4338ca;margin:20px 0;">';
echo '<h2>Results</h2>';
echo '<div class="box">';
echo "<p class=\"ok\">✅ Applied: <b>$changes / 8</b> changes</p>";
echo "<p class=\"info\">📄 File size: $original_size → $new_size bytes</p>";

if (!empty($errors)) {
    echo '<p class="warn">⚠️ Failed steps: ' . implode(', ', $errors) . '</p>';
    echo '<p class="info">These may have already been applied or the file structure differs slightly.</p>';
}

echo '</div>';

echo '<div class="box" style="background:#1e3a5f;">';
echo '<p class="info"><b>What changed:</b></p>';
echo '<p>• <b>Sent Back</b> card bold value = UNSETTLED BALANCE</p>';
echo '<p>• <b>Returned</b> card bold value = UNSETTLED BALANCE</p>';
echo '<p>• <b>Total Cheques</b> = Pending + TBB + Deposited + Cleared + SB Bal + RTN Bal</p>';
echo '<p>• <b>Cheques in Hand</b> = Pending + TBB + Deposited + SB Balance</p>';
echo '<p>• Status column shows <span class="ok">SB Settled</span> / <span class="ok">RTN Settled</span> when fully paid</p>';
echo '<p>• Status column shows Paid + Balance amounts when partially settled</p>';
echo '</div>';

echo '<p class="err" style="margin-top:20px;">⚠️ <b>DELETE THIS FILE</b> (patch_cheques_settlement.php) after patching!</p>';
echo '</body></html>';

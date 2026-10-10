#!/usr/bin/env python3
"""
apply_settlement_patch.py
=========================
Run: python3 apply_settlement_patch.py cheques.php
Creates a backup (cheques.php.bak) and applies all 8 settlement status changes.
"""
import sys, os, shutil

if len(sys.argv) < 2:
    print("Usage: python3 apply_settlement_patch.py <path-to-cheques.php>")
    sys.exit(1)

filepath = sys.argv[1]
if not os.path.exists(filepath):
    print(f"Error: File not found: {filepath}")
    sys.exit(1)

# Backup
backup = filepath + '.bak'
shutil.copy2(filepath, backup)
print(f"Backup created: {backup}")

with open(filepath, 'r', encoding='utf-8') as f:
    content = f.read()

changes = 0
errors = []

# ─── CHANGE 1: Add settlement columns to AJAX cheque_rows SELECT ───
old1 = """               ch.cheque_front_image, ch.cheque_back_image,
               (SELECT cba.account_holder_name FROM customer_bank_accounts cba
                WHERE cba.customer_id=c.id ORDER BY cba.id LIMIT 1) AS acc_holder_name"""
new1 = """               ch.cheque_front_image, ch.cheque_back_image,
               COALESCE(ch.return_settled, 0) AS return_settled,
               COALESCE(ch.settlement_amount, 0) AS return_settlement_amount,
               COALESCE(ch.sb_settled, 0) AS sb_settled,
               COALESCE(ch.sb_settlement_amount, 0) AS sb_settlement_amount,
               (SELECT cba.account_holder_name FROM customer_bank_accounts cba
                WHERE cba.customer_id=c.id ORDER BY cba.id LIMIT 1) AS acc_holder_name"""
if old1 in content:
    content = content.replace(old1, new1, 1); changes += 1
    print("  ✓ [1/8] Added settlement columns to cheque_rows SELECT")
else: errors.append("[1/8] cheque_rows SELECT — pattern not found")

# ─── CHANGE 2: Update statusCell() JS function ───
old2 = """function statusCell(id, st) {
    const lbl = status_labels_js[st] || st;
    const icon = status_icons_js[st] || 'fa-circle-dot';
    return `<span class="status-badge-clickable ${st}" onclick="openStatusUpdateModal(${id},'${st}')" title="Click to change status"><i class="fa-solid ${icon}"></i> ${lbl}</span>`;
}"""
new2 = """function statusCell(id, st, row) {
    const lbl = status_labels_js[st] || st;
    const icon = status_icons_js[st] || 'fa-circle-dot';
    const cheqAmt = parseFloat(row.total_amount || 0);
    if (st === 'sent_back') {
        const sbSettled = parseInt(row.sb_settled || 0);
        const sbPaid = parseFloat(row.sb_settlement_amount || 0);
        const sbBal = Math.max(0, cheqAmt - sbPaid);
        if (sbSettled) {
            return `<div style="text-align:center;"><span class="status-badge-clickable cleared" onclick="openStatusUpdateModal(${id},'${st}')" title="Fully settled" style="background:#d1fae5;color:#065f46;border-color:#6ee7b7;"><i class="fa-solid fa-circle-check"></i> SB Settled</span><div style="font-size:10px;color:#065f46;margin-top:3px;">Paid: Rs.${sbPaid.toFixed(2)}</div></div>`;
        } else if (sbPaid > 0) {
            return `<div style="text-align:center;"><span class="status-badge-clickable sent_back" onclick="openStatusUpdateModal(${id},'${st}')" title="Click to change status"><i class="fa-solid fa-rotate-left"></i> Sent Back</span><div style="font-size:10px;color:#92400e;margin-top:3px;">Paid: Rs.${sbPaid.toFixed(2)} | Bal: Rs.${sbBal.toFixed(2)}</div></div>`;
        } else {
            return `<div style="text-align:center;"><span class="status-badge-clickable sent_back" onclick="openStatusUpdateModal(${id},'${st}')" title="Click to change status"><i class="fa-solid fa-rotate-left"></i> Sent Back</span><div style="font-size:10px;color:#9ca3af;margin-top:3px;">Bal: Rs.${cheqAmt.toFixed(2)}</div></div>`;
        }
    }
    if (st === 'returned') {
        const rtnSettled = parseInt(row.return_settled || 0);
        const rtnPaid = parseFloat(row.return_settlement_amount || 0);
        const rtnBal = Math.max(0, cheqAmt - rtnPaid);
        if (rtnSettled) {
            return `<div style="text-align:center;"><span class="status-badge-clickable cleared" onclick="openStatusUpdateModal(${id},'${st}')" title="Fully settled" style="background:#d1fae5;color:#065f46;border-color:#6ee7b7;"><i class="fa-solid fa-circle-check"></i> RTN Settled</span><div style="font-size:10px;color:#065f46;margin-top:3px;">Paid: Rs.${rtnPaid.toFixed(2)}</div></div>`;
        } else if (rtnPaid > 0) {
            return `<div style="text-align:center;"><span class="status-badge-clickable returned" onclick="openStatusUpdateModal(${id},'${st}')" title="Click to change status"><i class="fa-solid fa-circle-xmark"></i> Returned</span><div style="font-size:10px;color:#92400e;margin-top:3px;">Paid: Rs.${rtnPaid.toFixed(2)} | Bal: Rs.${rtnBal.toFixed(2)}</div></div>`;
        } else {
            return `<div style="text-align:center;"><span class="status-badge-clickable returned" onclick="openStatusUpdateModal(${id},'${st}')" title="Click to change status"><i class="fa-solid fa-circle-xmark"></i> Returned</span><div style="font-size:10px;color:#9ca3af;margin-top:3px;">Bal: Rs.${cheqAmt.toFixed(2)}</div></div>`;
        }
    }
    return `<span class="status-badge-clickable ${st}" onclick="openStatusUpdateModal(${id},'${st}')" title="Click to change status"><i class="fa-solid ${icon}"></i> ${lbl}</span>`;
}"""
if old2 in content:
    content = content.replace(old2, new2, 1); changes += 1
    print("  ✓ [2/8] Updated statusCell() with settlement display")
else: errors.append("[2/8] statusCell() — pattern not found")

# ─── CHANGE 3: Pass row to statusCell ───
old3 = '          <td class="tc">${statusCell(id,st)}</td>'
new3 = '          <td class="tc">${statusCell(id,st,row)}</td>'
if old3 in content:
    content = content.replace(old3, new3, 1); changes += 1
    print("  ✓ [3/8] Updated statusCell call in renderRows")
else: errors.append("[3/8] statusCell call — pattern not found")

# ─── CHANGE 4: PHP settlement balance queries ───
old4 = """$in_hand_amount = $status_totals['pending']['amount'] + $status_totals['to_be_bank']['amount'] + $status_totals['deposited']['amount'];
$in_hand_count  = $status_totals['pending']['count']  + $status_totals['to_be_bank']['count']  + $status_totals['deposited']['count'];"""
new4 = """/* ── Calculate sent_back and returned unsettled balances ── */
$sb_bal_r = mysqli_query($conn, "SELECT
    COALESCE(SUM(GREATEST(total_amount - COALESCE(sb_settlement_amount,0), 0)),0) AS sb_balance,
    COUNT(CASE WHEN COALESCE(sb_settled,0)=0 THEN 1 END) AS sb_unsettled_cnt,
    COUNT(CASE WHEN COALESCE(sb_settled,0)=1 THEN 1 END) AS sb_settled_cnt
    FROM cheques WHERE status='sent_back'");
$sb_bal_data = $sb_bal_r ? mysqli_fetch_assoc($sb_bal_r) : ['sb_balance'=>0,'sb_unsettled_cnt'=>0,'sb_settled_cnt'=>0];
$sb_unsettled_balance = (float)($sb_bal_data['sb_balance'] ?? 0);

$rtn_bal_r = mysqli_query($conn, "SELECT
    COALESCE(SUM(GREATEST(total_amount - COALESCE(settlement_amount,0), 0)),0) AS rtn_balance,
    COUNT(CASE WHEN COALESCE(return_settled,0)=0 THEN 1 END) AS rtn_unsettled_cnt,
    COUNT(CASE WHEN COALESCE(return_settled,0)=1 THEN 1 END) AS rtn_settled_cnt
    FROM cheques WHERE status='returned' OR status='bounced'");
$rtn_bal_data = $rtn_bal_r ? mysqli_fetch_assoc($rtn_bal_r) : ['rtn_balance'=>0,'rtn_unsettled_cnt'=>0,'rtn_settled_cnt'=>0];
$rtn_unsettled_balance = (float)($rtn_bal_data['rtn_balance'] ?? 0);

$in_hand_amount = $status_totals['pending']['amount'] + $status_totals['to_be_bank']['amount'] + $status_totals['deposited']['amount'] + $sb_unsettled_balance;
$in_hand_count  = $status_totals['pending']['count']  + $status_totals['to_be_bank']['count']  + $status_totals['deposited']['count'] + (int)($sb_bal_data['sb_unsettled_cnt'] ?? 0);"""
if old4 in content:
    content = content.replace(old4, new4, 1); changes += 1
    print("  ✓ [4/8] Added PHP settlement balance queries")
else: errors.append("[4/8] in_hand calculation — pattern not found")

# ─── CHANGE 5: Cheques in Hand stat card ───
old5 = '''<div class="stat-card" style="border-color:#4f46e5;background:#f0f4ff;cursor:pointer;" onclick="filterByStatusMulti(['pending','to_be_bank','deposited'])" title="Cheques in Hand (Pending + To Be Bank + Deposited)">
  <div class="stat-label"><i class="fa-solid fa-hand-holding" style="color:#4f46e5;"></i> Cheques in Hand</div>
  <div class="stat-value" style="color:#3730a3;font-size:15px;">Rs.&nbsp;<?=number_format($in_hand_amount,0)?></div>
  <div class="stat-card-amt"><?=$in_hand_count?> cheques</div>
</div>'''
new5 = '''<div class="stat-card" style="border-color:#4f46e5;background:#f0f4ff;cursor:pointer;" onclick="filterByStatusMulti(['pending','to_be_bank','deposited'])" title="Cheques in Hand (Pending + To Be Bank + Deposited + SB Balance)">
  <div class="stat-label"><i class="fa-solid fa-hand-holding" style="color:#4f46e5;"></i> Cheques in Hand</div>
  <div class="stat-value" style="color:#3730a3;font-size:15px;">Rs.&nbsp;<?=number_format($in_hand_amount,0)?></div>
  <div class="stat-card-amt"><?=$in_hand_count?> cheques<?php if($sb_unsettled_balance > 0): ?> <span style="font-size:9px;color:#7c3aed;">(incl. SB Bal: Rs.<?=number_format($sb_unsettled_balance,0)?>)</span><?php endif; ?></div>
</div>'''
if old5 in content:
    content = content.replace(old5, new5, 1); changes += 1
    print("  ✓ [5/8] Updated Cheques in Hand stat card")
else: errors.append("[5/8] Cheques in Hand stat card — pattern not found")

# ─── CHANGE 6: Sent Back stat card ───
old6 = """<div class="stat-card <?=$f_status==='sent_back'?'active-filter':''?>" onclick="filterByStatus('sent_back')"><div class="stat-label"><i class="fa-solid fa-rotate-left" style="color:#7c3aed;"></i> Sent Back</div><div class="stat-value sv-violet" style="font-size:15px;">Rs.&nbsp;<?=number_format($status_totals['sent_back']['amount'],0)?></div><div class="stat-card-amt"><?=$status_totals['sent_back']['count']?> cheques</div></div>"""
new6 = """<div class="stat-card <?=$f_status==='sent_back'?'active-filter':''?>" onclick="filterByStatus('sent_back')"><div class="stat-label"><i class="fa-solid fa-rotate-left" style="color:#7c3aed;"></i> Sent Back</div><div class="stat-value sv-violet" style="font-size:15px;">Rs.&nbsp;<?=number_format($status_totals['sent_back']['amount'],0)?></div><div class="stat-card-amt"><?=$status_totals['sent_back']['count']?> cheques<?php if($sb_unsettled_balance > 0): ?> &middot; <span style="color:#d97706;">Bal: Rs.<?=number_format($sb_unsettled_balance,0)?></span><?php endif; ?><?php if((int)($sb_bal_data['sb_settled_cnt']??0) > 0): ?> &middot; <span style="color:#16a34a;"><?=(int)$sb_bal_data['sb_settled_cnt']?> settled</span><?php endif; ?></div></div>"""
if old6 in content:
    content = content.replace(old6, new6, 1); changes += 1
    print("  ✓ [6/8] Updated Sent Back stat card")
else: errors.append("[6/8] Sent Back stat card — pattern not found")

# ─── CHANGE 7: Returned stat card ───
old7 = """<div class="stat-card <?=$f_status==='returned'?'active-filter':''?>" onclick="filterByStatus('returned')"><div class="stat-label"><i class="fa-solid fa-circle-xmark" style="color:#dc2626;"></i> Returned</div><div class="stat-value sv-red" style="font-size:15px;">Rs.&nbsp;<?=number_format($status_totals['returned']['amount'],0)?></div><div class="stat-card-amt"><?=$status_totals['returned']['count']?> cheques</div></div>"""
new7 = """<div class="stat-card <?=$f_status==='returned'?'active-filter':''?>" onclick="filterByStatus('returned')"><div class="stat-label"><i class="fa-solid fa-circle-xmark" style="color:#dc2626;"></i> Returned</div><div class="stat-value sv-red" style="font-size:15px;">Rs.&nbsp;<?=number_format($status_totals['returned']['amount'],0)?></div><div class="stat-card-amt"><?=$status_totals['returned']['count']?> cheques<?php if($rtn_unsettled_balance > 0): ?> &middot; <span style="color:#d97706;">Bal: Rs.<?=number_format($rtn_unsettled_balance,0)?></span><?php endif; ?><?php if((int)($rtn_bal_data['rtn_settled_cnt']??0) > 0): ?> &middot; <span style="color:#16a34a;"><?=(int)$rtn_bal_data['rtn_settled_cnt']?> settled</span><?php endif; ?></div></div>"""
if old7 in content:
    content = content.replace(old7, new7, 1); changes += 1
    print("  ✓ [7/8] Updated Returned stat card")
else: errors.append("[7/8] Returned stat card — pattern not found")

# ─── CHANGE 8: Add settlement data attributes to row ───
old8 = 'data-mode="${esc(row.cheque_mode||\'\')}\">'
new8 = 'data-mode="${esc(row.cheque_mode||\'\')}\" data-sb-settled="${row.sb_settled||0}" data-sb-amt="${row.sb_settlement_amount||0}" data-rtn-settled="${row.return_settled||0}" data-rtn-amt="${row.return_settlement_amount||0}">'
if old8 in content:
    content = content.replace(old8, new8, 1); changes += 1
    print("  ✓ [8/8] Added settlement data attributes to rows")
else: errors.append("[8/8] Row data-mode attribute — pattern not found")

# Write result
with open(filepath, 'w', encoding='utf-8') as f:
    f.write(content)

print(f"\n{'='*50}")
print(f"Applied: {changes}/8 changes")
if errors:
    print(f"\nWarnings ({len(errors)}):")
    for e in errors:
        print(f"  ⚠ {e}")
print(f"\nBackup saved: {backup}")
print(f"Modified: {filepath}")
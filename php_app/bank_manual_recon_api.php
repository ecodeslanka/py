<?php
/**
 * bank_manual_recon_api.php
 * ─────────────────────────────────────────────────────────────────────
 * Manual reconcile of ONE bank statement line with a reason.
 * Used by bank_datewise_transactions.php and bank_statements.php (view).
 *
 *  POST action=save  txn_id, reason_id, remark(optional), csrf
 *      → bank_statement_transactions.recon_status   = 'reconciled'
 *                                    recon_source   = 'manual_recon'
 *                                    recon_category = 'Manual Reconcile'
 *                                    recon_ref_id   = bank_manual_recon.id
 *                                    recon_remark   = 'Reason: … | Remark: …'
 *      Only a line that is NOT reconciled yet can be saved.
 *
 *  POST action=undo  txn_id, csrf
 *      → clears the line again, only when it was reconciled manually.
 *
 *  Every save / undo is kept in bank_manual_recon (history).
 * ─────────────────────────────────────────────────────────────────────
 */
date_default_timezone_set('Asia/Colombo');

ob_start();
include_once 'config.php';
include_once 'auth.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

function bmr_out($ok, $msg, $extra = []) { echo json_encode(['ok' => $ok, 'msg' => $msg] + $extra); exit; }

if (!function_exists('isLoggedIn') || !isLoggedIn()) bmr_out(false, 'Your session has expired. Log in again and retry.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') bmr_out(false, 'Invalid request.');
if (empty($_SESSION['bmr_csrf']) || !hash_equals((string)$_SESSION['bmr_csrf'], (string)($_POST['csrf'] ?? ''))) {
    bmr_out(false, 'Security check failed. Reload the page and try again.');
}

require_once __DIR__ . '/bank_manual_recon_lib.php';
bmr_ensure($conn);

$user   = isset($_SESSION['username']) ? (string)$_SESSION['username'] : 'unknown';
$now    = date('Y-m-d H:i:s');
$action = (string)($_POST['action'] ?? '');
$tid    = (int)($_POST['txn_id'] ?? 0);
if ($tid <= 0) bmr_out(false, 'Bank line not found.');

try {
    mysqli_begin_transaction($conn);

    $r  = mysqli_query($conn, "SELECT id, recon_status, recon_source, recon_ref_id, credit, debit FROM bank_statement_transactions WHERE id = $tid FOR UPDATE");
    $tx = $r ? mysqli_fetch_assoc($r) : null;
    if (!$tx) throw new RuntimeException('Bank line not found. It may have been deleted.');

    /* ───────────── SAVE ───────────── */
    if ($action === 'save') {
        if (!empty($tx['recon_status'])) throw new RuntimeException('This bank line is already reconciled. Reload the page.');

        $rid = (int)($_POST['reason_id'] ?? 0);
        $r   = mysqli_query($conn, "SELECT id, reason FROM bank_recon_reasons WHERE id = $rid AND active = 1");
        $rs  = $r ? mysqli_fetch_assoc($r) : null;
        if (!$rs) throw new RuntimeException('Please select a reason.');

        $remark = trim(preg_replace('/\s+/', ' ', (string)($_POST['remark'] ?? '')));
        if (mb_strlen($remark) > 500) $remark = mb_substr($remark, 0, 500);
        $amount = (float)$tx['credit'] > 0 ? (float)$tx['credit'] : (float)$tx['debit'];
        $side   = (float)$tx['credit'] > 0 ? 'CR' : 'DR';

        $st = mysqli_prepare($conn, "INSERT INTO bank_manual_recon (bank_txn_id, reason_id, reason, remark, amount, side, created_by, created_at)
                                     VALUES (?,?,?,?,?,?,?,?)");
        mysqli_stmt_bind_param($st, 'iissdsss', $tid, $rid, $rs['reason'], $remark, $amount, $side, $user, $now);
        if (!mysqli_stmt_execute($st)) throw new RuntimeException('Could not save: ' . mysqli_error($conn));
        $mid = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($st);

        $full = 'Manual Recon #' . $mid . ' | Reason: ' . $rs['reason'] . ($remark !== '' ? ' | Remark: ' . $remark : '');
        $status = 'reconciled'; $src = BMR_SOURCE; $cat = BMR_CATEGORY;
        $st = mysqli_prepare($conn, "UPDATE bank_statement_transactions
                                        SET recon_status = ?, recon_source = ?, recon_category = ?, recon_ref_id = ?, recon_remark = ?, recon_by = ?, recon_at = ?
                                      WHERE id = ? AND (recon_status IS NULL OR recon_status = '')");
        mysqli_stmt_bind_param($st, 'sssisssi', $status, $src, $cat, $mid, $full, $user, $now, $tid);
        mysqli_stmt_execute($st);
        if (mysqli_stmt_affected_rows($st) !== 1) throw new RuntimeException('This bank line was just reconciled by someone else.');
        mysqli_stmt_close($st);

        mysqli_commit($conn);
        bmr_out(true, 'Saved as manually reconciled.', [
            'recon_id' => $mid, 'remark' => $full, 'category' => BMR_CATEGORY,
            'by' => $user, 'at' => date('d M Y H:i', strtotime($now)),
        ]);
    }

    /* ───────────── UNDO ───────────── */
    if ($action === 'undo') {
        if (($tx['recon_source'] ?? '') !== BMR_SOURCE) throw new RuntimeException('Only manually reconciled lines can be undone here.');
        $mid = (int)$tx['recon_ref_id'];
        mysqli_query($conn, "UPDATE bank_statement_transactions
                                SET recon_status = NULL, recon_source = NULL, recon_category = NULL, recon_ref_id = NULL,
                                    recon_remark = NULL, recon_by = NULL, recon_at = NULL
                              WHERE id = $tid AND recon_source = '" . BMR_SOURCE . "'");
        if ($mid > 0) {
            $st = mysqli_prepare($conn, "UPDATE bank_manual_recon SET undone_by = ?, undone_at = ? WHERE id = ?");
            mysqli_stmt_bind_param($st, 'ssi', $user, $now, $mid);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
        }
        mysqli_commit($conn);
        bmr_out(true, 'Manual reconcile removed. The line is not reconciled again.');
    }

    throw new RuntimeException('Unknown action.');
} catch (Throwable $e) {
    @mysqli_rollback($conn);
    bmr_out(false, $e->getMessage());
}

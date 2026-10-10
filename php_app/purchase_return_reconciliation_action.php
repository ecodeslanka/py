<?php
include 'config.php';

$action             = trim($_POST['action']             ?? '');
$inv_no             = trim($_POST['inv_no']             ?? '');
$ledger_id          = intval($_POST['ledger_id']        ?? 0);
$credit             = (float)($_POST['credit']          ?? 0);
$company            = trim($_POST['company']            ?? '');
$company_filter     = trim($_POST['company_filter']     ?? '');
$customer_reference = trim($_POST['customer_reference'] ?? '');
$txn_date           = trim($_POST['txn_date']           ?? '');

if (!$action || !$inv_no || !$ledger_id) {
    header('Location: purchase_return_summary_report.php');
    exit;
}

$inv_safe  = mysqli_real_escape_string($conn, $inv_no);
$co_safe   = mysqli_real_escape_string($conn, $company);
$cref_safe = mysqli_real_escape_string($conn, $customer_reference);
$txnd_safe = mysqli_real_escape_string($conn, $txn_date);
$now       = date('Y-m-d H:i:s');

// ── Back URL ──────────────────────────────────────────────────────────────────
$back_url = 'purchase_return_reconciliation.php?inv_no=' . urlencode($inv_no)
          . ($company_filter ? '&company=' . urlencode($company_filter) : '');

if ($action === 'link') {

    // 1. Update purchase_return_register_details — mark reconciled + store credit
    $u1 = mysqli_query($conn,
        "UPDATE purchase_return_register_details
         SET
             reconciled                    = 1,
             reconciled_ledger_id          = $ledger_id,
             reconciled_credit             = $credit,
             reconciled_company            = '$co_safe',
             reconciled_customer_reference = '$cref_safe',
             reconciled_txn_date           = " . ($txnd_safe !== '' ? "'$txnd_safe'" : "NULL") . ",
             reconciled_at                 = '$now'
         WHERE company_invoice_number = '$inv_safe'");

    // 2. Update ulcl_ledger — mark this row as used by this invoice
    $inv_esc = mysqli_real_escape_string($conn, $inv_no);
    $u2 = mysqli_query($conn,
        "UPDATE ulcl_ledger
         SET reconciled_pr_inv = '$inv_esc',
             reconciled_at     = '$now'
         WHERE id = $ledger_id");

    if ($u1 && $u2) {
        $_SESSION['flash'] = ['type' => 'success', 'msg' => "Invoice <strong>" . htmlspecialchars($inv_no) . "</strong> successfully reconciled with ledger row #$ledger_id (Credit: " . number_format($credit, 2) . ")."];
    } else {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => "Reconciliation failed. DB error: " . mysqli_error($conn)];
    }

} elseif ($action === 'unlink') {

    // 1. Reset purchase_return_register_details
    $u1 = mysqli_query($conn,
        "UPDATE purchase_return_register_details
         SET
             reconciled                    = 0,
             reconciled_ledger_id          = NULL,
             reconciled_credit             = NULL,
             reconciled_company            = NULL,
             reconciled_customer_reference = NULL,
             reconciled_txn_date           = NULL,
             reconciled_at                 = NULL
         WHERE company_invoice_number = '$inv_safe'");

    // 2. Reset ulcl_ledger
    $u2 = mysqli_query($conn,
        "UPDATE ulcl_ledger
         SET reconciled_pr_inv = NULL,
             reconciled_at     = NULL
         WHERE id = $ledger_id");

    if ($u1 && $u2) {
        $_SESSION['flash'] = ['type' => 'success', 'msg' => "Reconciliation for <strong>" . htmlspecialchars($inv_no) . "</strong> has been unlinked."];
    } else {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => "Unlink failed. DB error: " . mysqli_error($conn)];
    }

}

header('Location: ' . $back_url);
exit;
?>
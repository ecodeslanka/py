<?php
/* ══════════════════════════════════════════════════════════════════
   link_recreate_invoice.php

   AJAX endpoint used by the "Link Recreated Invoice" picker on fs_se.php.
   Links an se_charges row that was marked "Mark to Recreate Invoice" to
   the invoice (field_summary_details row) that is the recreation of it,
   which must be on a DIFFERENT day than the charge's own original invoice.

   POST params: charge_id, target_fs_id, target_detail_id
   Returns: {"success":true} or {"success":false,"error":"..."}
══════════════════════════════════════════════════════════════════ */
include 'config.php';
header('Content-Type: application/json');

$charge_id        = isset($_POST['charge_id']) ? intval($_POST['charge_id']) : 0;
$target_fs_id     = isset($_POST['target_fs_id']) ? intval($_POST['target_fs_id']) : 0;
$target_detail_id = isset($_POST['target_detail_id']) ? intval($_POST['target_detail_id']) : 0;

if (!$charge_id || !$target_fs_id || !$target_detail_id) {
    echo json_encode(['success' => false, 'error' => 'Missing charge or target invoice.']);
    exit;
}

/* load the charge and confirm it is actually marked to recreate an invoice */
$chg_res = mysqli_query($conn, "SELECT * FROM se_charges WHERE id = " . intval($charge_id) . " LIMIT 1");
$chg = $chg_res ? mysqli_fetch_assoc($chg_res) : null;
if (!$chg) { echo json_encode(['success' => false, 'error' => 'Charge not found.']); exit; }
if (intval($chg['mark_recreate_invoice']) !== 1) {
    echo json_encode(['success' => false, 'error' => 'This charge is not marked to recreate an invoice.']);
    exit;
}

/* can't link a charge to its own original invoice row */
if (intval($target_detail_id) === intval($chg['field_summary_detail_id'])) {
    echo json_encode(['success' => false, 'error' => 'Cannot link an invoice to itself — pick the recreated invoice from a different day.']);
    exit;
}

/* confirm target detail exists and belongs to target_fs_id, and load its date */
$date_col = 'delivery_date';
$chk = mysqli_query($conn, "SHOW COLUMNS FROM field_summary LIKE 'delivery_date'");
if (!$chk || mysqli_num_rows($chk) === 0) {
    $chk2 = mysqli_query($conn, "SHOW COLUMNS FROM field_summary LIKE 'visit_date'");
    if ($chk2 && mysqli_num_rows($chk2) > 0) {
        $date_col = 'visit_date';
    } else {
        $chk3 = mysqli_query($conn, "SHOW COLUMNS FROM field_summary LIKE 'summary_date'");
        if ($chk3 && mysqli_num_rows($chk3) > 0) $date_col = 'summary_date';
    }
}

$tgt_res = mysqli_query($conn, "
    SELECT d.id, d.field_summary_id, d.to_be_delivery, d.to_be_delivery_date, fs.$date_col AS fs_date
    FROM field_summary_details d
    JOIN field_summary fs ON fs.id = d.field_summary_id
    WHERE d.id = " . intval($target_detail_id) . " LIMIT 1");
$tgt = $tgt_res ? mysqli_fetch_assoc($tgt_res) : null;
if (!$tgt || intval($tgt['field_summary_id']) !== $target_fs_id) {
    echo json_encode(['success' => false, 'error' => 'Target invoice not found.']);
    exit;
}
$target_eff_date = (intval($tgt['to_be_delivery']) === 1 && !empty($tgt['to_be_delivery_date']))
    ? $tgt['to_be_delivery_date'] : $tgt['fs_date'];

/* load the ORIGINAL invoice's own effective date and enforce "different day" */
$orig_res = mysqli_query($conn, "
    SELECT d.to_be_delivery, d.to_be_delivery_date, fs.$date_col AS fs_date
    FROM field_summary_details d
    JOIN field_summary fs ON fs.id = d.field_summary_id
    WHERE d.id = " . intval($chg['field_summary_detail_id']) . " LIMIT 1");
$orig = $orig_res ? mysqli_fetch_assoc($orig_res) : null;
if ($orig) {
    $orig_eff_date = (intval($orig['to_be_delivery']) === 1 && !empty($orig['to_be_delivery_date']))
        ? $orig['to_be_delivery_date'] : $orig['fs_date'];
    if ($orig_eff_date === $target_eff_date) {
        echo json_encode(['success' => false, 'error' => 'The recreated invoice must be on a different day than the original invoice.']);
        exit;
    }
}

/* re-confirm the SOURCE charge (the one being linked) is still a pending,
   unlinked "CO Mistake" + recreate-checked charge — guards against a race
   where it was linked or edited between the picker loading and this click.
   Note: the TARGET invoice does NOT need any charge of its own — any
   invoice can serve as the recreation, which is the whole point of this
   flow (it is picked from the target invoice's own row). */
if (!empty($chg['recreate_linked_detail_id'])) {
    echo json_encode(['success' => false, 'error' => 'That charge is already linked to another invoice.']);
    exit;
}

/* one target invoice can only be used as the recreate-link for one charge */
$dupe_res = mysqli_query($conn, "SELECT id FROM se_charges WHERE recreate_linked_detail_id = " . intval($target_detail_id) . " AND id <> " . intval($charge_id) . " LIMIT 1");
if ($dupe_res && mysqli_num_rows($dupe_res) > 0) {
    echo json_encode(['success' => false, 'error' => 'That invoice is already linked to another charge.']);
    exit;
}

$ok = mysqli_query($conn, "UPDATE se_charges
    SET recreate_linked_fs_id = " . intval($target_fs_id) . ",
        recreate_linked_detail_id = " . intval($target_detail_id) . ",
        recreate_invoice_settled = 0
    WHERE id = " . intval($charge_id));

if ($ok) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
}

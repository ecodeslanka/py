<?php
include 'config.php';
header('Content-Type: application/json');

/* ── Auto-create credit_notes table if it doesn't exist ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `credit_notes` (
    `id`                       INT AUTO_INCREMENT PRIMARY KEY,
    `field_summary_detail_id`  INT NOT NULL,
    `amount`                   DECIMAL(12,2) NOT NULL,
    `reason`                   TEXT,
    `note_date`                DATE NOT NULL,
    `created_at`               DATETIME DEFAULT CURRENT_TIMESTAMP,
    `is_deleted`               TINYINT(1) NOT NULL DEFAULT 0,
    INDEX `idx_fsd_id` (`field_summary_detail_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── also handle note_date column missing on older installs ── */
$chk = mysqli_query($conn,"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='credit_notes' AND COLUMN_NAME='note_date' LIMIT 1");
if($chk && mysqli_num_rows($chk)===0)
    mysqli_query($conn,"ALTER TABLE credit_notes ADD COLUMN `note_date` DATE NOT NULL DEFAULT (CURDATE()) AFTER reason");

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

switch ($action) {

    /* ────────────────────────────────────────────────
       LIST  — GET  ?action=list&detail_id=N
    ──────────────────────────────────────────────── */
    case 'list':
        $detail_id = intval($_GET['detail_id'] ?? 0);
        if (!$detail_id) { echo json_encode(['success'=>false,'error'=>'Missing detail_id']); exit; }

        $res = mysqli_query($conn,
            "SELECT id, amount, reason, note_date,
                    DATE_FORMAT(note_date,'%d %b %Y') AS note_date_fmt,
                    DATE_FORMAT(created_at,'%d %b %Y %H:%i') AS created_fmt
             FROM   credit_notes
             WHERE  field_summary_detail_id = $detail_id
               AND  is_deleted = 0
             ORDER  BY created_at DESC");
        $notes = [];
        while ($r = mysqli_fetch_assoc($res)) $notes[] = $r;
        $total_cn = array_sum(array_column($notes,'amount'));
        echo json_encode(['success'=>true,'notes'=>$notes,'total_cn'=>(float)$total_cn]);
        break;

    /* ────────────────────────────────────────────────
       ADD  — POST  action=add
    ──────────────────────────────────────────────── */
    case 'add':
        $detail_id = intval($_POST['detail_id'] ?? 0);
        $amount    = floatval($_POST['amount']    ?? 0);
        $reason    = trim($_POST['reason']        ?? '');
        $note_date = trim($_POST['note_date']     ?? date('Y-m-d'));

        if (!$detail_id || $amount <= 0) {
            echo json_encode(['success'=>false,'error'=>'Invalid amount or missing detail_id']);
            exit;
        }

        // Validate date
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $note_date)) $note_date = date('Y-m-d');

        $reason_esc    = mysqli_real_escape_string($conn, $reason);
        $note_date_esc = mysqli_real_escape_string($conn, $note_date);

        mysqli_query($conn,
            "INSERT INTO credit_notes (field_summary_detail_id, amount, reason, note_date)
             VALUES ($detail_id, $amount, '$reason_esc', '$note_date_esc')");
        $new_id = mysqli_insert_id($conn);

        if (!$new_id) {
            echo json_encode(['success'=>false,'error'=>'Insert failed: '.mysqli_error($conn)]);
            exit;
        }

        $tr = mysqli_query($conn,
            "SELECT COALESCE(SUM(amount),0) AS t FROM credit_notes
             WHERE field_summary_detail_id=$detail_id AND is_deleted=0");
        $total_cn = (float)(mysqli_fetch_assoc($tr)['t'] ?? 0);

        echo json_encode([
            'success'    => true,
            'id'         => $new_id,
            'total_cn'   => $total_cn,
            'amount'     => $amount,
            'reason'     => $reason,
            'note_date'  => $note_date,
            'note_date_fmt' => date('d M Y', strtotime($note_date))
        ]);
        break;

    /* ────────────────────────────────────────────────
       DELETE  — POST  action=delete
    ──────────────────────────────────────────────── */
    case 'delete':
        $id = intval($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success'=>false,'error'=>'Missing id']); exit; }

        $r = mysqli_query($conn,
            "SELECT field_summary_detail_id, amount FROM credit_notes WHERE id=$id LIMIT 1");
        $cn_row    = $r ? mysqli_fetch_assoc($r) : null;
        $detail_id = intval($cn_row['field_summary_detail_id'] ?? 0);

        mysqli_query($conn, "UPDATE credit_notes SET is_deleted=1 WHERE id=$id");

        $total_cn = 0.0;
        if ($detail_id) {
            $tr = mysqli_query($conn,
                "SELECT COALESCE(SUM(amount),0) AS t FROM credit_notes
                 WHERE field_summary_detail_id=$detail_id AND is_deleted=0");
            $total_cn = (float)(mysqli_fetch_assoc($tr)['t'] ?? 0);
        }

        echo json_encode(['success'=>true,'total_cn'=>$total_cn,'deleted_amount'=>(float)($cn_row['amount']??0)]);
        break;

    default:
        echo json_encode(['success'=>false,'error'=>'Unknown action']);
}

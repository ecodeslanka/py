<?php
/**
 * save_cash_transfer.php
 * Handles create / update / delete for cash_shortage_transfers.
 * Supports employee_id and employee_name columns.
 */
header('Content-Type: application/json');
include 'config.php';

/* ── read JSON body ── */
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

$action = $data['action'] ?? 'create';

/* ════════════════════════════════════════════════
   Helper: build the row-summary for a given
   (delivery_date, sr_code) so the front-end can
   update the table without a page reload.
   ════════════════════════════════════════════════ */
function buildRowSummary($conn, $sr_code, $delivery_date, $direction)
{
    $sr  = mysqli_real_escape_string($conn, $sr_code);
    $dd  = mysqli_real_escape_string($conn, $delivery_date);

    if ($direction === 'from') {
        $q = "SELECT id, to_sr_code AS other_sr, delivery_date, transfer_date,
                     amount, note, employee_id, employee_name,
                     'out' AS direction
              FROM cash_shortage_transfers
              WHERE from_sr_code='$sr' AND delivery_date='$dd'
              UNION ALL
              SELECT id, from_sr_code AS other_sr, delivery_date, transfer_date,
                     amount, note, employee_id, employee_name,
                     'in' AS direction
              FROM cash_shortage_transfers
              WHERE to_sr_code='$sr' AND delivery_date='$dd'
              ORDER BY id";
    } else {
        $q = "SELECT id, from_sr_code AS other_sr, delivery_date, transfer_date,
                     amount, note, employee_id, employee_name,
                     'in' AS direction
              FROM cash_shortage_transfers
              WHERE to_sr_code='$sr' AND delivery_date='$dd'
              UNION ALL
              SELECT id, to_sr_code AS other_sr, delivery_date, transfer_date,
                     amount, note, employee_id, employee_name,
                     'out' AS direction
              FROM cash_shortage_transfers
              WHERE from_sr_code='$sr' AND delivery_date='$dd'
              ORDER BY id";
    }

    $res    = mysqli_query($conn, $q);
    $t_rows = [];
    $t_out  = 0.0;
    $t_in   = 0.0;

    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $t_rows[] = [
                'id'            => intval($r['id']),
                'direction'     => $r['direction'],
                'other_sr'      => $r['other_sr'],
                'delivery_date' => $r['delivery_date'],
                'transfer_date' => $r['transfer_date'],
                'amount'        => floatval($r['amount']),
                'note'          => $r['note'],
                'employee_id'   => $r['employee_id'] ? intval($r['employee_id']) : null,
                'employee_name' => $r['employee_name'],
            ];
            if ($r['direction'] === 'out') $t_out += floatval($r['amount']);
            else                           $t_in  += floatval($r['amount']);
        }
    }

    $shortage_adj = $t_out - $t_in;
    /* We don't have bank_diff here, so we just return transfer totals;
       the front-end recalculates new_short_excess using its cached bank_diff. */
    return [
        't_rows'          => $t_rows,
        't_out'           => $t_out,
        't_in'            => $t_in,
        'shortage_adj'    => $shortage_adj,
        /* Placeholder — front-end JS will recalculate properly */
        'new_short_excess'=> null,
    ];
}

/* ════════════════════════════════════════════════
   CREATE
   ════════════════════════════════════════════════ */
if ($action === 'create') {
    $from_sr      = trim($data['from_sr_code']  ?? '');
    $to_sr        = trim($data['to_sr_code']    ?? '');
    $del_date     = trim($data['delivery_date'] ?? '');
    $tr_date      = trim($data['transfer_date'] ?? '');
    $amount       = floatval($data['amount']    ?? 0);
    $note         = trim($data['note']          ?? '');
    $employee_id  = isset($data['employee_id'])  && $data['employee_id']  ? intval($data['employee_id'])  : null;
    $employee_name= isset($data['employee_name'])&& $data['employee_name'] ? trim($data['employee_name']) : null;

    if (!$from_sr || !$to_sr || !$del_date || !$tr_date || $amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }
    if ($from_sr === $to_sr) {
        echo json_encode(['success' => false, 'message' => 'Cannot transfer to same rep']);
        exit;
    }

    $fs  = mysqli_real_escape_string($conn, $from_sr);
    $ts  = mysqli_real_escape_string($conn, $to_sr);
    $dd  = mysqli_real_escape_string($conn, $del_date);
    $trd = mysqli_real_escape_string($conn, $tr_date);
    $am  = floatval($amount);
    $nt  = mysqli_real_escape_string($conn, $note);
    $eid = $employee_id ? intval($employee_id) : 'NULL';
    $enm = $employee_name ? "'".mysqli_real_escape_string($conn, $employee_name)."'" : 'NULL';

    $q = "INSERT INTO cash_shortage_transfers
              (from_sr_code, to_sr_code, delivery_date, transfer_date, amount, note, employee_id, employee_name)
          VALUES ('$fs','$ts','$dd','$trd',$am,'$nt',$eid,$enm)";

    if (mysqli_query($conn, $q)) {
        $new_id = mysqli_insert_id($conn);
        echo json_encode([
            'success'      => true,
            'transfer_id'  => $new_id,
            'from_row_data'=> buildRowSummary($conn, $from_sr, $del_date, 'from'),
            'to_row_data'  => buildRowSummary($conn, $to_sr,   $del_date, 'to'),
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

/* ════════════════════════════════════════════════
   UPDATE
   ════════════════════════════════════════════════ */
if ($action === 'update') {
    $id           = intval($data['id'] ?? 0);
    $to_sr        = trim($data['to_sr_code']    ?? '');
    $del_date     = trim($data['delivery_date'] ?? '');
    $tr_date      = trim($data['transfer_date'] ?? '');
    $amount       = floatval($data['amount']    ?? 0);
    $note         = trim($data['note']          ?? '');
    $employee_id  = isset($data['employee_id'])  && $data['employee_id']  ? intval($data['employee_id'])  : null;
    $employee_name= isset($data['employee_name'])&& $data['employee_name'] ? trim($data['employee_name']) : null;

    if (!$id || !$to_sr || !$del_date || !$tr_date || $amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }

    /* fetch original record to know from_sr and old to_sr */
    $orig = mysqli_query($conn, "SELECT from_sr_code, to_sr_code, delivery_date FROM cash_shortage_transfers WHERE id=$id");
    if (!$orig || mysqli_num_rows($orig) === 0) {
        echo json_encode(['success' => false, 'message' => 'Transfer not found']);
        exit;
    }
    $orig_row  = mysqli_fetch_assoc($orig);
    $from_sr   = $orig_row['from_sr_code'];
    $old_to_sr = $orig_row['to_sr_code'];
    $old_del   = $orig_row['delivery_date'];

    if ($from_sr === $to_sr) {
        echo json_encode(['success' => false, 'message' => 'Cannot transfer to same rep']);
        exit;
    }

    $ts  = mysqli_real_escape_string($conn, $to_sr);
    $dd  = mysqli_real_escape_string($conn, $del_date);
    $trd = mysqli_real_escape_string($conn, $tr_date);
    $am  = floatval($amount);
    $nt  = mysqli_real_escape_string($conn, $note);
    $eid = $employee_id ? intval($employee_id) : 'NULL';
    $enm = $employee_name ? "'".mysqli_real_escape_string($conn, $employee_name)."'" : 'NULL';

    $q = "UPDATE cash_shortage_transfers
          SET to_sr_code='$ts', delivery_date='$dd', transfer_date='$trd',
              amount=$am, note='$nt', employee_id=$eid, employee_name=$enm
          WHERE id=$id";

    if (mysqli_query($conn, $q)) {
        $response = [
            'success'      => true,
            'transfer_id'  => $id,
            'from_row_data'=> buildRowSummary($conn, $from_sr, $del_date, 'from'),
            'to_row_data'  => buildRowSummary($conn, $to_sr,   $del_date, 'to'),
        ];
        /* If target rep changed, also send data for the old target */
        if ($old_to_sr !== $to_sr || $old_del !== $del_date) {
            $response['old_to_row_data'] = buildRowSummary($conn, $old_to_sr, $old_del, 'to');
        }
        echo json_encode($response);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

/* ════════════════════════════════════════════════
   DELETE
   ════════════════════════════════════════════════ */
if ($action === 'delete') {
    $id = intval($data['id'] ?? 0);
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Invalid ID']);
        exit;
    }

    /* fetch before deleting so we can rebuild summaries */
    $orig = mysqli_query($conn, "SELECT from_sr_code, to_sr_code, delivery_date FROM cash_shortage_transfers WHERE id=$id");
    if (!$orig || mysqli_num_rows($orig) === 0) {
        echo json_encode(['success' => false, 'message' => 'Transfer not found']);
        exit;
    }
    $orig_row = mysqli_fetch_assoc($orig);
    $from_sr  = $orig_row['from_sr_code'];
    $to_sr    = $orig_row['to_sr_code'];
    $del_date = $orig_row['delivery_date'];

    if (mysqli_query($conn, "DELETE FROM cash_shortage_transfers WHERE id=$id")) {
        echo json_encode([
            'success'      => true,
            'from_row_data'=> buildRowSummary($conn, $from_sr, $del_date, 'from'),
            'to_row_data'  => buildRowSummary($conn, $to_sr,   $del_date, 'to'),
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
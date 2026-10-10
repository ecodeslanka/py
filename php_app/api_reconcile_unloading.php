<?php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();
include 'config.php';
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

function jexit($arr){ echo json_encode($arr); exit; }
function num($v){ return is_numeric($v) ? round(floatval($v), 2) : 0.0; }

try {
    if (!$conn) jexit(['success' => false, 'message' => 'DB connection failed']);

    // ── Ensure reconciliation table ─────────────────────────────────
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS unloading_reconciliation (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        delivery_date       DATE NOT NULL,
        sku_code            VARCHAR(100) NULL,
        sku_desc            VARCHAR(500) NULL,
        base_short_excess   DECIMAL(12,2) NULL,
        excel_short_excess  DECIMAL(12,2) NULL,
        difference          DECIMAL(12,2) NULL,
        status              VARCHAR(20) NOT NULL DEFAULT 'unreconciled',
        match_source        VARCHAR(20) NOT NULL DEFAULT 'both',
        remark              VARCHAR(255) NULL,
        excel_batch         VARCHAR(64) NULL,
        created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_date      (delivery_date),
        INDEX idx_sku       (sku_code),
        INDEX idx_status    (status)
    )");

    $raw    = file_get_contents('php://input');
    $body   = $raw ? json_decode($raw, true) : [];
    $action = $_GET['action'] ?? ($body['action'] ?? '');

    // Normalize a delivery date string -> 'Y-m-d' or false
    function norm_date($s) {
        $s = trim((string)$s);
        if ($s === '') return false;
        $ts = strtotime($s);
        return $ts ? date('Y-m-d', $ts) : false;
    }
    // Normalize a sku code for matching (trim + uppercase, collapse spaces)
    function norm_code($s) {
        $s = strtoupper(trim((string)$s));
        $s = preg_replace('/\s+/', ' ', $s);
        return $s;
    }

    // Computes matched/unmatched rows between system base data and uploaded excel rows.
    // Does NOT touch the DB — pure computation, reused by both 'reconcile_preview' and 'reconcile_save'.
    function compute_reconciliation($conn, $dd, $dds, $excel_rows) {
        // ── 1. Load fresh base data (grouped by sku_code) ─────────
        $base = []; // norm_code => ['sku_code'=>, 'sku_desc'=>, 'val'=>]
        $r = mysqli_query($conn,
            "SELECT sku_code, MAX(sku_desc) AS sku_desc,
                    SUM(COALESCE(short_excess,0)) AS val
             FROM unloading_data
             WHERE delivery_date = '$dds' AND sku_code IS NOT NULL AND sku_code <> ''
             GROUP BY sku_code
             HAVING SUM(COALESCE(short_excess,0)) <> 0");
        if ($r) while ($row = mysqli_fetch_assoc($r)) {
            $key = norm_code($row['sku_code']);
            $base[$key] = [
                'sku_code' => $row['sku_code'],
                'sku_desc' => $row['sku_desc'],
                'val'      => num($row['val'])
            ];
        }

        // ── 2. Aggregate excel rows ────────────────────────────────
        // Remark rule: "Opening Stock" (or containing "open") => EXCESS (+qty)
        //              anything else (e.g. "Short", "Shortage", blank)  => SHORT (-qty)
        $excel = []; // norm_code => ['sku_code'=>, 'sku_desc'=>, 'val'=>]
        foreach ($excel_rows as $er) {
            $code = trim((string)($er['sku_code'] ?? ($er['item_code'] ?? '')));
            $desc = trim((string)($er['sku_desc'] ?? ($er['item_name'] ?? '')));
            $remark = trim((string)($er['remark'] ?? ''));
            $qty = num($er['qty'] ?? 0);
            if ($code === '' || $qty == 0) continue;

            $remark_lc = strtolower($remark);
            $is_opening = (strpos($remark_lc, 'open') !== false); // "Opening Stock" -> excess
            $signed = $is_opening ? abs($qty) : -abs($qty);

            $key = norm_code($code);
            if (!isset($excel[$key])) {
                $excel[$key] = ['sku_code' => $code, 'sku_desc' => $desc, 'val' => 0.0, 'remarks' => []];
            }
            $excel[$key]['val'] += $signed;
            $excel[$key]['val'] = round($excel[$key]['val'], 2);
            if ($desc !== '' && $excel[$key]['sku_desc'] === '') $excel[$key]['sku_desc'] = $desc;
            $rlabel = $is_opening ? 'Opening Stock' : ($remark !== '' ? $remark : 'Short');
            if (!in_array($rlabel, $excel[$key]['remarks'])) $excel[$key]['remarks'][] = $rlabel;
        }

        // ── 3. Merge base + excel, decide reconciled / unreconciled ─
        $all_keys = array_unique(array_merge(array_keys($base), array_keys($excel)));
        $result_rows = [];
        $tolerance = 0.01;
        $reconciled_ct = 0; $unreconciled_ct = 0;

        foreach ($all_keys as $key) {
            $b = isset($base[$key]) ? $base[$key] : null;
            $e = isset($excel[$key]) ? $excel[$key] : null;

            $sku_code = $b['sku_code'] ?? $e['sku_code'];
            $sku_desc = $b['sku_desc'] ?? $e['sku_desc'];
            $base_val = $b ? $b['val'] : null;
            $excel_val = $e ? $e['val'] : null;

            if ($b && $e) {
                $diff = round($base_val - $excel_val, 2);
                $status = (abs($diff) <= $tolerance) ? 'reconciled' : 'unreconciled';
                $source = 'both';
                $remark = implode(', ', $e['remarks']);
            } elseif ($b && !$e) {
                $diff = $base_val;
                $status = 'unreconciled';
                $source = 'base_only';
                $remark = 'No matching item in uploaded excel';
            } else {
                $diff = -$excel_val;
                $status = 'unreconciled';
                $source = 'excel_only';
                $remark = 'Not found in system data (' . implode(', ', $e['remarks']) . ')';
            }

            if ($status === 'reconciled') $reconciled_ct++; else $unreconciled_ct++;

            $result_rows[] = [
                'sku_code' => $sku_code,
                'sku_desc' => $sku_desc,
                'base_short_excess' => $base_val,
                'excel_short_excess' => $excel_val,
                'difference' => $diff,
                'status' => $status,
                'match_source' => $source,
                'remark' => $remark
            ];
        }

        // sort: unreconciled first, then by abs(difference) desc
        usort($result_rows, function($a, $b2) {
            if ($a['status'] !== $b2['status']) return $a['status'] === 'unreconciled' ? -1 : 1;
            return abs($b2['difference']) <=> abs($a['difference']);
        });

        return [$result_rows, $reconciled_ct, $unreconciled_ct];
    }

    switch ($action) {

        // ─── LIST DATES with data ────────────────────────────────────
        case 'dates':
            $rows = [];
            $r = mysqli_query($conn,
                "SELECT delivery_date, COUNT(*) c,
                        SUM(CASE WHEN COALESCE(short_excess,0) < 0 THEN 1 ELSE 0 END) short_items,
                        SUM(CASE WHEN COALESCE(short_excess,0) > 0 THEN 1 ELSE 0 END) excess_items
                 FROM unloading_data
                 WHERE delivery_date IS NOT NULL
                 GROUP BY delivery_date
                 ORDER BY delivery_date DESC
                 LIMIT 365");
            if ($r) while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;
            jexit(['success' => true, 'dates' => $rows]);
            break;

        // ─── BASE DATA: system short/excess for a delivery date ──────
        case 'base_data':
            $dd = norm_date($_GET['delivery_date'] ?? '');
            if (!$dd) jexit(['success' => false, 'message' => 'Invalid delivery_date']);
            $dds = mysqli_real_escape_string($conn, $dd);

            $rows = [];
            $r = mysqli_query($conn,
                "SELECT sku_code,
                        MAX(sku_desc) AS sku_desc,
                        SUM(COALESCE(short_excess,0)) AS base_short_excess,
                        COUNT(*) AS line_count
                 FROM unloading_data
                 WHERE delivery_date = '$dds' AND sku_code IS NOT NULL AND sku_code <> ''
                 GROUP BY sku_code
                 HAVING SUM(COALESCE(short_excess,0)) <> 0
                 ORDER BY base_short_excess ASC");
            $total_short = 0; $total_excess = 0; $short_ct = 0; $excess_ct = 0;
            if ($r) {
                while ($row = mysqli_fetch_assoc($r)) {
                    $v = num($row['base_short_excess']);
                    $row['base_short_excess'] = $v;
                    if ($v < 0) { $total_short += $v; $short_ct++; }
                    elseif ($v > 0) { $total_excess += $v; $excess_ct++; }
                    $rows[] = $row;
                }
            }

            // has this date already been reconciled / saved before?
            $recon_cnt = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT COUNT(*) c FROM unloading_reconciliation WHERE delivery_date = '$dds'"));

            jexit([
                'success' => true,
                'delivery_date' => $dd,
                'rows' => $rows,
                'totals' => [
                    'total_short'  => round($total_short, 2),
                    'total_excess' => round($total_excess, 2),
                    'short_items'  => $short_ct,
                    'excess_items' => $excess_ct,
                    'net'          => round($total_short + $total_excess, 2)
                ],
                'already_reconciled' => ($recon_cnt && $recon_cnt['c'] > 0)
            ]);
            break;

        // ─── RECONCILE PREVIEW: match uploaded excel rows against base data, don't save ──
        case 'reconcile_preview':
            $dd = norm_date($body['delivery_date'] ?? '');
            if (!$dd) jexit(['success' => false, 'message' => 'Invalid delivery_date']);
            $dds = mysqli_real_escape_string($conn, $dd);

            $excel_rows = (isset($body['rows']) && is_array($body['rows'])) ? $body['rows'] : [];
            if (count($excel_rows) === 0) jexit(['success' => false, 'message' => 'No excel rows received']);

            list($result_rows, $reconciled_ct, $unreconciled_ct) = compute_reconciliation($conn, $dd, $dds, $excel_rows);

            jexit([
                'success' => true,
                'delivery_date' => $dd,
                'rows' => $result_rows,
                'summary' => [
                    'total' => count($result_rows),
                    'reconciled' => $reconciled_ct,
                    'unreconciled' => $unreconciled_ct
                ]
            ]);
            break;

        // ─── RECONCILE SAVE: match uploaded excel rows against base data, then persist ──
        case 'reconcile_save':
        case 'reconcile': // legacy alias
            $dd = norm_date($body['delivery_date'] ?? '');
            if (!$dd) jexit(['success' => false, 'message' => 'Invalid delivery_date']);
            $dds = mysqli_real_escape_string($conn, $dd);

            $excel_rows = (isset($body['rows']) && is_array($body['rows'])) ? $body['rows'] : [];
            if (count($excel_rows) === 0) jexit(['success' => false, 'message' => 'No excel rows received']);

            $batch = 'B' . date('YmdHis');
            list($result_rows, $reconciled_ct, $unreconciled_ct) = compute_reconciliation($conn, $dd, $dds, $excel_rows);

            // ── Persist: replace any previous reconciliation for this date ─
            mysqli_query($conn, "DELETE FROM unloading_reconciliation WHERE delivery_date = '$dds'");

            $ins_ok = 0; $ins_fail = 0;
            $stmt_sql = "INSERT INTO unloading_reconciliation
                (delivery_date, sku_code, sku_desc, base_short_excess, excel_short_excess,
                 difference, status, match_source, remark, excel_batch)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = mysqli_prepare($conn, $stmt_sql);
            if ($stmt) {
                foreach ($result_rows as $rr) {
                    $sc = $rr['sku_code']; $sd = $rr['sku_desc'];
                    $bv = $rr['base_short_excess']; $ev = $rr['excel_short_excess']; $df = $rr['difference'];
                    $st = $rr['status']; $ms = $rr['match_source']; $rm = $rr['remark'];
                    mysqli_stmt_bind_param($stmt, 'sssdddssss',
                        $dd, $sc, $sd, $bv, $ev, $df, $st, $ms, $rm, $batch);
                    if (mysqli_stmt_execute($stmt)) $ins_ok++; else $ins_fail++;
                }
                mysqli_stmt_close($stmt);
            } else {
                // fallback to manual insert if prepare fails
                foreach ($result_rows as $rr) {
                    $sc = mysqli_real_escape_string($conn, $rr['sku_code'] ?? '');
                    $sd = mysqli_real_escape_string($conn, $rr['sku_desc'] ?? '');
                    $bv = $rr['base_short_excess'] === null ? 'NULL' : num($rr['base_short_excess']);
                    $ev = $rr['excel_short_excess'] === null ? 'NULL' : num($rr['excel_short_excess']);
                    $df = num($rr['difference']);
                    $st = mysqli_real_escape_string($conn, $rr['status']);
                    $ms = mysqli_real_escape_string($conn, $rr['match_source']);
                    $rm = mysqli_real_escape_string($conn, $rr['remark']);
                    $q = "INSERT INTO unloading_reconciliation
                        (delivery_date, sku_code, sku_desc, base_short_excess, excel_short_excess,
                         difference, status, match_source, remark, excel_batch)
                        VALUES ('$dds','$sc','$sd',$bv,$ev,$df,'$st','$ms','$rm','$batch')";
                    if (mysqli_query($conn, $q)) $ins_ok++; else $ins_fail++;
                }
            }

            jexit([
                'success' => true,
                'delivery_date' => $dd,
                'rows' => $result_rows,
                'summary' => [
                    'total' => count($result_rows),
                    'reconciled' => $reconciled_ct,
                    'unreconciled' => $unreconciled_ct,
                    'saved' => $ins_ok,
                    'save_errors' => $ins_fail
                ]
            ]);
            break;

        // ─── GET SAVED RECONCILIATION for a date ──────────────────────
        case 'get_reconciled':
            $dd = norm_date($_GET['delivery_date'] ?? '');
            if (!$dd) jexit(['success' => false, 'message' => 'Invalid delivery_date']);
            $dds = mysqli_real_escape_string($conn, $dd);

            $rows = [];
            $r = mysqli_query($conn,
                "SELECT id, sku_code, sku_desc, base_short_excess, excel_short_excess,
                        difference, status, match_source, remark, updated_at
                 FROM unloading_reconciliation
                 WHERE delivery_date = '$dds'
                 ORDER BY (status='unreconciled') DESC, ABS(COALESCE(difference,0)) DESC");
            $reconciled_ct = 0; $unreconciled_ct = 0;
            if ($r) while ($row = mysqli_fetch_assoc($r)) {
                if ($row['status'] === 'reconciled') $reconciled_ct++; else $unreconciled_ct++;
                $rows[] = $row;
            }
            jexit([
                'success' => true,
                'delivery_date' => $dd,
                'rows' => $rows,
                'summary' => [
                    'total' => count($rows),
                    'reconciled' => $reconciled_ct,
                    'unreconciled' => $unreconciled_ct
                ]
            ]);
            break;

        // ─── DELETE RECONCILIATION: remove the whole saved reconciliation for a date ──
        case 'delete_reconciliation':
            $dd = norm_date($_GET['delivery_date'] ?? ($body['delivery_date'] ?? ''));
            if (!$dd) jexit(['success' => false, 'message' => 'Invalid delivery_date']);
            $dds = mysqli_real_escape_string($conn, $dd);

            $cnt_row = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT COUNT(*) c FROM unloading_reconciliation WHERE delivery_date = '$dds'"));
            $existing = $cnt_row ? intval($cnt_row['c']) : 0;
            if ($existing === 0) jexit(['success' => false, 'message' => 'No saved reconciliation found for this date']);

            if (mysqli_query($conn, "DELETE FROM unloading_reconciliation WHERE delivery_date = '$dds'")) {
                jexit(['success' => true, 'delivery_date' => $dd, 'deleted' => $existing]);
            } else {
                jexit(['success' => false, 'message' => mysqli_error($conn)]);
            }
            break;

        // ─── DELETE ROW: remove a single saved reconciliation row ──────
        case 'delete_row':
            $id = intval($body['id'] ?? ($_GET['id'] ?? 0));
            if (!$id) jexit(['success' => false, 'message' => 'Invalid id']);

            if (mysqli_query($conn, "DELETE FROM unloading_reconciliation WHERE id = $id")) {
                if (mysqli_affected_rows($conn) > 0) {
                    jexit(['success' => true, 'id' => $id]);
                } else {
                    jexit(['success' => false, 'message' => 'Row not found']);
                }
            } else {
                jexit(['success' => false, 'message' => mysqli_error($conn)]);
            }
            break;

        // ─── MANUAL OVERRIDE: force a row's status ─────────────────────
        case 'update_status':
            $id = intval($body['id'] ?? 0);
            $status = in_array(($body['status'] ?? ''), ['reconciled', 'unreconciled']) ? $body['status'] : '';
            $remark = mysqli_real_escape_string($conn, trim($body['remark'] ?? ''));
            if (!$id || !$status) jexit(['success' => false, 'message' => 'Invalid id/status']);

            $q = "UPDATE unloading_reconciliation SET status = '$status'"
               . ($remark !== '' ? ", remark = '$remark'" : "")
               . " WHERE id = $id";
            if (mysqli_query($conn, $q)) {
                jexit(['success' => true, 'id' => $id, 'status' => $status]);
            } else {
                jexit(['success' => false, 'message' => mysqli_error($conn)]);
            }
            break;

        default:
            jexit(['success' => false, 'message' => 'Unknown action: ' . $action]);
    }

} catch (Exception $e) {
    jexit(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
} catch (Error $e) {
    jexit(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
exit;
?>

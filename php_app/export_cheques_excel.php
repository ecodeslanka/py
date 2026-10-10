<?php
/**
 * export_cheques_excel.php
 * Exports ALL cheque records (respecting active filters) to a formatted .xlsx file.
 * Uses PhpSpreadsheet (falls back to CSV). No paging — full dataset.
 *
 * SAMPATH UPDATE:
 *  - INNER JOIN → LEFT JOIN on invoice_payments / field_summary so Sampath
 *    Super Payment cheques (invoice_payment_id = NULL) are included.
 *  - Supports the "Sampath cheques only" filter (sampath_only=1).
 *  - Search also matches sampath_description / ch.amount (same as cheque_rows).
 *  - New columns: Type, Sampath Payment #, Sampath Description.
 *  - Sampath rows highlighted amber + Sampath subtotal row.
 *  - Cheque No / codes written as text so leading zeros are kept.
 */

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

if (session_status() === PHP_SESSION_NONE) session_start();
include 'config.php';

/* ── Filters from GET (same params as cheque_rows AJAX) ── */
$f_sr       = trim($_GET['sr_code']       ?? '');
$f_status   = trim($_GET['status']        ?? '');
$f_bank     = trim($_GET['bank_code']     ?? '');
$f_tcode    = trim($_GET['t_code']        ?? '');
$f_from     = trim($_GET['date_from']     ?? '');
$f_to       = trim($_GET['date_to']       ?? '');
$f_del_from = trim($_GET['del_date_from'] ?? '');
$f_del_to   = trim($_GET['del_date_to']   ?? '');
$f_rec_from = trim($_GET['rec_date_from'] ?? '');
$f_rec_to   = trim($_GET['rec_date_to']   ?? '');
$search     = trim($_GET['q']             ?? '');
$f_sampath_only = trim($_GET['sampath_only'] ?? '') === '1';

$esc = fn($v) => mysqli_real_escape_string($conn, $v);

/* ── Build WHERE (identical logic to cheque_rows AJAX) ── */
$where = ["1=1"];
if ($f_sr) $where[] = "fs.sr_code='".$esc($f_sr)."'";
$st_arr = [];
if ($f_status) {
    $st_arr = array_values(array_filter(array_map('trim', explode(',', $f_status))));
    if (count($st_arr) === 1) {
        $where[] = "ch.status='".$esc($st_arr[0])."'";
    } elseif (count($st_arr) > 1) {
        $in = implode(',', array_map(fn($s)=>"'".$esc($s)."'", $st_arr));
        $where[] = "ch.status IN ($in)";
    }
}
if ($f_bank)     $where[] = "ch.bank_code='".$esc($f_bank)."'";
if ($f_tcode)    $where[] = "ch.t_code LIKE '%".$esc($f_tcode)."%'";
if ($f_from)     $where[] = "ch.cheque_date>='".$esc($f_from)."'";
if ($f_to)       $where[] = "ch.cheque_date<='".$esc($f_to)."'";
if ($f_del_from) $where[] = "fs.delivery_date>='".$esc($f_del_from)."'";
if ($f_del_to)   $where[] = "fs.delivery_date<='".$esc($f_del_to)."'";
if ($f_rec_from) $where[] = "COALESCE(ch.received_date, ip.payment_date)>='".$esc($f_rec_from)."'";
if ($f_rec_to)   $where[] = "COALESCE(ch.received_date, ip.payment_date)<='".$esc($f_rec_to)."'";
if ($f_sampath_only) $where[] = "ch.sampath IS NOT NULL AND ch.sampath != ''";

if ($search !== '') {
    $s = '%'.$esc($search).'%';
    $where[] = "(
        ch.cheque_no       LIKE '$s'
        OR ch.t_code       LIKE '$s'
        OR ch.bank_code    LIKE '$s'
        OR ch.bank_name    LIKE '$s'
        OR ch.branch_name  LIKE '$s'
        OR ch.branch_code  LIKE '$s'
        OR ch.status       LIKE '$s'
        OR ch.cheque_mode  LIKE '$s'
        OR fs.sr_code      LIKE '$s'
        OR COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ch.t_code) LIKE '$s'
        OR CAST(ch.total_amount AS CHAR) LIKE '$s'
        OR CAST(ch.amount AS CHAR) LIKE '$s'
        OR FORMAT(ch.total_amount,2) LIKE '$s'
        OR FORMAT(ch.amount,2) LIKE '$s'
        OR DATE_FORMAT(ch.cheque_date,'%d %b %Y') LIKE '$s'
        OR DATE_FORMAT(ch.cheque_date,'%Y-%m-%d') LIKE '$s'
        OR DATE_FORMAT(fs.delivery_date,'%d %b %Y') LIKE '$s'
        OR DATE_FORMAT(fs.delivery_date,'%Y-%m-%d') LIKE '$s'
        OR ch.sampath_description LIKE '$s'
    )";
}

$where_sql = implode(' AND ', $where);

/* ── Fetch ALL matching rows (no LIMIT) ──
   LEFT JOIN (not INNER) — Sampath Super Payment cheques have no
   invoice_payments / field_summary row, INNER JOIN would drop them. */
$sql = "
    SELECT
        ch.id,
        ch.cheque_no,
        ch.cheque_date,
        ch.amount,
        ch.total_amount,
        ch.bank_code,
        ch.bank_name,
        ch.branch_code,
        ch.branch_name,
        ch.status,
        ch.t_code,
        COALESCE(NULLIF(ch.cheque_mode,''), '') AS cheque_mode,
        ch.bulk_flag,
        COALESCE(ch.received_date, ip.payment_date) AS received_date,
        fs.sr_code,
        fs.delivery_date,
        COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, NULLIF(ch.t_code,''),
                 CASE WHEN ch.sampath IS NOT NULL AND ch.sampath != ''
                      THEN CONCAT('Sampath Payment #', COALESCE(ch.sampath_payment_id,0))
                      ELSE NULL END) AS customer_name,
        COALESCE(ch.verified, 0) AS verified,
        ch.deposit_date,
        ch.deposit_type,
        ch.to_be_bank_date,
        ch.sent_back_reason,
        ch.sampath,
        ch.sampath_payment_id,
        ch.sampath_description,
        (SELECT cba.account_holder_name FROM customer_bank_accounts cba
         WHERE cba.customer_id=c.id ORDER BY cba.id LIMIT 1) AS acc_holder_name
    FROM cheques ch
    LEFT JOIN invoice_payments      ip  ON ip.id  = ch.invoice_payment_id
    LEFT JOIN field_summary         fs  ON fs.id  = ip.field_summary_id
    LEFT JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
    LEFT JOIN customers             c   ON c.t_code = ch.t_code
    WHERE $where_sql
    ORDER BY ch.cheque_date DESC, ch.cheque_no ASC
";

$res  = mysqli_query($conn, $sql);
if (!$res) {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Database error: '.mysqli_error($conn);
    exit;
}
$rows = [];
while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;

/* ── Shared labels / helpers ── */
$STATUS_LABELS = [
    'pending'=>'Pending','to_be_bank'=>'To Be Bank','deposited'=>'Deposited',
    'sent_back'=>'Sent Back','cleared'=>'Cleared','returned'=>'Returned'
];
$MODE_LABELS = [
    'payee_only'=>'Payee Only','payee only'=>'Payee Only','payee'=>'Payee Only',
    'account payee'=>'Payee Only','a/c payee'=>'Payee Only','ac payee'=>'Payee Only',
    'order'=>'Payee Only','cash'=>'Bearer/Cash','bearer'=>'Bearer/Cash',
    'open'=>'Bearer/Cash','third_party_cash'=>'3rd Party Cash',
    'third party cash'=>'3rd Party Cash','3rd party'=>'3rd Party Cash',
    'third party'=>'3rd Party Cash',
];
$DEP_TYPE_LABELS = ['normal'=>'Normal','bulk'=>'Bulk','normal_bulk'=>'Normal Bulk'];

function fmt_date($d) {
    if (!$d || strpos($d, '0000-00-00') === 0) return '';
    $t = strtotime($d);
    return $t ? date('d M Y', $t) : '';
}

/* Build one export row (used by both XLSX and CSV) */
function build_row($i, $r, $STATUS_LABELS, $MODE_LABELS, $DEP_TYPE_LABELS) {
    $st = strtolower(trim($r['status'] ?? 'pending'));
    if ($st === 'bounced') $st = 'returned';
    $cm = strtolower(trim($r['cheque_mode'] ?? ''));
    $is_samp = !empty($r['sampath']);
    $sr = $is_samp
        ? 'Sampath'.(!empty($r['sampath_payment_id']) ? ' #'.$r['sampath_payment_id'] : '')
        : ($r['sr_code'] ?? '');
    $dt = strtolower(trim($r['deposit_type'] ?? ''));
    return [
        'no'          => $i + 1,
        'type'        => $is_samp ? 'Sampath' : 'Normal',
        'sr'          => $sr,
        'delivery'    => fmt_date($r['delivery_date'] ?? ''),
        'received'    => fmt_date($r['received_date'] ?? ''),
        'cheque_date' => fmt_date($r['cheque_date'] ?? ''),
        'cheque_no'   => $r['cheque_no'] ?? '',
        'mode'        => $MODE_LABELS[$cm] ?? ($r['cheque_mode'] ?? ''),
        'bank_name'   => $r['bank_name'] ?? '',
        'bank_code'   => $r['bank_code'] ?? '',
        'branch_name' => $r['branch_name'] ?? '',
        'branch_code' => $r['branch_code'] ?? '',
        'amount'      => (float)($r['total_amount'] ?? 0),
        'status'      => $STATUS_LABELS[$st] ?? $st,
        'verified'    => intval($r['verified'] ?? 0) ? 'Yes' : 'No',
        'bulk'        => intval($r['bulk_flag'] ?? 0) ? 'Yes' : 'No',
        't_code'      => $r['t_code'] ?? '',
        'customer'    => $r['customer_name'] ?? '',
        'acc_holder'  => $r['acc_holder_name'] ?? '',
        'tbb_date'    => fmt_date($r['to_be_bank_date'] ?? ''),
        'dep_date'    => fmt_date($r['deposit_date'] ?? ''),
        'dep_type'    => $dt ? ($DEP_TYPE_LABELS[$dt] ?? ucfirst($dt)) : '',
        'sb_reason'   => $r['sent_back_reason'] ?? '',
        'samp_pid'    => $is_samp ? (string)($r['sampath_payment_id'] ?? '') : '',
        'samp_desc'   => $is_samp ? ($r['sampath_description'] ?? '') : '',
        '_st'         => $st,
        '_samp'       => $is_samp,
    ];
}

/* ── Column definitions: key => [header, width, align] ── */
$COLUMNS = [
    'no'          => ['#',                  5,  'center'],
    'type'        => ['Type',               10, 'center'],
    'sr'          => ['SR Code',            14, 'center'],
    'delivery'    => ['Delivery Date',      13, 'center'],
    'received'    => ['Received Date',      13, 'center'],
    'cheque_date' => ['Cheque Date',        13, 'center'],
    'cheque_no'   => ['Cheque No.',         16, 'left'],
    'mode'        => ['Mode',               13, 'left'],
    'bank_name'   => ['Bank Name',          22, 'left'],
    'bank_code'   => ['Bank Code',          10, 'center'],
    'branch_name' => ['Branch Name',        22, 'left'],
    'branch_code' => ['Branch Code',        11, 'center'],
    'amount'      => ['Amount (Rs.)',       14, 'right'],
    'status'      => ['Status',             12, 'center'],
    'verified'    => ['Verified',            9, 'center'],
    'bulk'        => ['Bulk Dep.',           9, 'center'],
    't_code'      => ['T-Code',             10, 'left'],
    'customer'    => ['Customer Name',      26, 'left'],
    'acc_holder'  => ['Acc. Holder',        22, 'left'],
    'tbb_date'    => ['To Be Bank Date',    14, 'center'],
    'dep_date'    => ['Deposit Date',       13, 'center'],
    'dep_type'    => ['Deposit Type',       12, 'center'],
    'sb_reason'   => ['Send Back Reason',   30, 'left'],
    'samp_pid'    => ['Sampath Payment #',  12, 'center'],
    'samp_desc'   => ['Sampath Description',34, 'left'],
];
/* Columns written as text (keep leading zeros) */
$TEXT_COLS = ['sr','cheque_no','bank_code','branch_code','t_code','samp_pid'];

/* Totals */
$grand_total = 0.0; $samp_count = 0; $samp_total = 0.0;
foreach ($rows as $r) {
    $a = (float)($r['total_amount'] ?? 0);
    $grand_total += $a;
    if (!empty($r['sampath'])) { $samp_count++; $samp_total += $a; }
}

/* ── Load PhpSpreadsheet ── */
$autoload_paths = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
    '/var/www/html/vendor/autoload.php',
];
$loaded = false;
foreach ($autoload_paths as $path) {
    if (file_exists($path)) { require $path; $loaded = true; break; }
}

/* ══════════════════════════════════════════════════════
   FALLBACK: CSV if PhpSpreadsheet not available
══════════════════════════════════════════════════════ */
if (!$loaded || !class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
    $filename = 'cheques_'.($f_sampath_only ? 'sampath_' : '').date('Ymd_Hi').'.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
    fputcsv($out, array_map(fn($c) => $c[0], $COLUMNS));
    foreach ($rows as $i => $r) {
        $d = build_row($i, $r, $STATUS_LABELS, $MODE_LABELS, $DEP_TYPE_LABELS);
        $line = [];
        foreach ($COLUMNS as $key => $_) {
            $line[] = $key === 'amount' ? number_format($d['amount'], 2, '.', '') : $d[$key];
        }
        fputcsv($out, $line);
    }
    if ($samp_count > 0 && !$f_sampath_only) {
        fputcsv($out, []);
        fputcsv($out, ['', 'SAMPATH SUBTOTAL', $samp_count.' cheques', number_format($samp_total, 2, '.', '')]);
    }
    fputcsv($out, ['', 'TOTAL', count($rows).' cheques', number_format($grand_total, 2, '.', '')]);
    fclose($out);
    exit;
}

/* ══════════════════════════════════════════════════════
   BUILD EXCEL WORKBOOK WITH PhpSpreadsheet
══════════════════════════════════════════════════════ */
$wb = new Spreadsheet();
$wb->getProperties()
    ->setCreator('Cheque Register System')
    ->setTitle('Cheque Register Export')
    ->setSubject('Cheques Export '.date('d M Y'));

$ws = $wb->getActiveSheet();
$ws->setTitle($f_sampath_only ? 'Sampath Cheques' : 'Cheques');

/* Column letters */
$L = []; $ci = 1;
foreach ($COLUMNS as $key => $_) { $L[$key] = Coordinate::stringFromColumnIndex($ci++); }
$FIRST = 'A';
$LAST  = end($L);

/* ── Colour palette ── */
$C_HEADER_BG  = '1E1B4B';
$C_HEADER_FG  = 'FFFFFF';
$C_TITLE_BG   = '312E81';
$C_TITLE_FG   = 'FFFFFF';
$C_ALT        = 'F5F3FF';
$C_SAMP       = 'FFFBEB';   // amber tint — Sampath rows
$C_SAMP_ALT   = 'FEF3C7';
$C_TOTAL_BG   = '0F172A';
$C_TOTAL_FG   = 'E2E8F0';

$STATUS_COLORS = [
    'pending'    => ['bg'=>'FEF3C7','fg'=>'92400E'],
    'to_be_bank' => ['bg'=>'E0F2FE','fg'=>'0369A1'],
    'deposited'  => ['bg'=>'DBEAFE','fg'=>'1E40AF'],
    'sent_back'  => ['bg'=>'EDE9FE','fg'=>'5B21B6'],
    'cleared'    => ['bg'=>'DCFCE7','fg'=>'166534'],
    'returned'   => ['bg'=>'FEE2E2','fg'=>'991B1B'],
];

/* ── Row 1: Report title ── */
$ws->mergeCells("{$FIRST}1:{$LAST}1");
$ws->setCellValue('A1', 'Cheque Register'.($f_sampath_only ? ' — Sampath Cheques Only' : '').' — Export '.date('d M Y H:i'));
$ws->getStyle('A1')->applyFromArray([
    'font'      => ['bold'=>true,'size'=>14,'color'=>['rgb'=>$C_TITLE_FG],'name'=>'Arial'],
    'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$C_TITLE_BG]],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],
]);
$ws->getRowDimension(1)->setRowHeight(28);

/* ── Row 2: Active filters info ── */
$filter_parts = [];
if ($f_sr)       $filter_parts[] = "SR: $f_sr";
if ($st_arr)     $filter_parts[] = "Status: ".implode(', ', array_map(fn($s)=>$STATUS_LABELS[$s] ?? $s, $st_arr));
if ($f_bank)     $filter_parts[] = "Bank: $f_bank";
if ($f_tcode)    $filter_parts[] = "T-Code: $f_tcode";
if ($f_from)     $filter_parts[] = "Cheque From: $f_from";
if ($f_to)       $filter_parts[] = "Cheque To: $f_to";
if ($f_del_from) $filter_parts[] = "Delivery From: $f_del_from";
if ($f_del_to)   $filter_parts[] = "Delivery To: $f_del_to";
if ($f_rec_from) $filter_parts[] = "Received From: $f_rec_from";
if ($f_rec_to)   $filter_parts[] = "Received To: $f_rec_to";
if ($f_sampath_only) $filter_parts[] = "Sampath cheques only";
if ($search)     $filter_parts[] = "Search: \"$search\"";

$ws->mergeCells("{$FIRST}2:{$LAST}2");
$filter_text = $filter_parts ? 'Filters: '.implode('   |   ', $filter_parts) : 'All cheques — no filters applied';
$ws->setCellValue('A2', $filter_text);
$ws->getStyle('A2')->applyFromArray([
    'font'      => ['italic'=>true,'size'=>9,'color'=>['rgb'=>'374151'],'name'=>'Arial'],
    'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'EEF2FF']],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_LEFT,'vertical'=>Alignment::VERTICAL_CENTER],
]);
$ws->getRowDimension(2)->setRowHeight(16);

/* ── Row 3: Column headers + widths ── */
foreach ($COLUMNS as $key => [$label, $width, $align]) {
    $ws->setCellValue($L[$key].'3', $label);
    $ws->getColumnDimension($L[$key])->setWidth($width);
}
$ws->getStyle("{$FIRST}3:{$LAST}3")->applyFromArray([
    'font'      => ['bold'=>true,'size'=>9,'color'=>['rgb'=>$C_HEADER_FG],'name'=>'Arial'],
    'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$C_HEADER_BG]],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER,'wrapText'=>false],
    'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'3730A3']]],
]);
/* Sampath header columns in amber */
$ws->getStyle($L['samp_pid'].'3:'.$L['samp_desc'].'3')->getFill()
   ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('B45309');
$ws->getRowDimension(3)->setRowHeight(20);

$ALIGN_MAP = [
    'left'   => Alignment::HORIZONTAL_LEFT,
    'center' => Alignment::HORIZONTAL_CENTER,
    'right'  => Alignment::HORIZONTAL_RIGHT,
];

/* ── Data rows (start at row 4) ── */
$data_row = 4;
foreach ($rows as $idx => $r) {
    $d = build_row($idx, $r, $STATUS_LABELS, $MODE_LABELS, $DEP_TYPE_LABELS);
    $is_alt  = ($idx % 2 === 1);
    $bg_color = $d['_samp'] ? ($is_alt ? $C_SAMP_ALT : $C_SAMP) : ($is_alt ? $C_ALT : 'FFFFFF');

    /* Write cells */
    foreach ($COLUMNS as $key => $_) {
        $cell = $L[$key].$data_row;
        if (in_array($key, $TEXT_COLS, true)) {
            $ws->setCellValueExplicit($cell, (string)$d[$key], DataType::TYPE_STRING);
        } else {
            $ws->setCellValue($cell, $d[$key]);
        }
    }

    /* Row base style */
    $ws->getStyle("{$FIRST}{$data_row}:{$LAST}{$data_row}")->applyFromArray([
        'font'      => ['size'=>9,'name'=>'Arial','color'=>['rgb'=>'1F2937']],
        'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$bg_color]],
        'alignment' => ['vertical'=>Alignment::VERTICAL_CENTER],
        'borders'   => ['bottom'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'E5E7EB']]],
    ]);

    /* Column alignment */
    foreach ($COLUMNS as $key => [, , $align]) {
        $ws->getStyle($L[$key].$data_row)->getAlignment()->setHorizontal($ALIGN_MAP[$align]);
    }

    /* Amount */
    $ws->getStyle($L['amount'].$data_row)->getNumberFormat()->setFormatCode('#,##0.00');
    $ws->getStyle($L['amount'].$data_row)->getFont()->setBold(true);

    /* Row number grey */
    $ws->getStyle($L['no'].$data_row)->getFont()->setColor(new Color('9CA3AF'));

    /* Type cell */
    if ($d['_samp']) {
        $ws->getStyle($L['type'].$data_row)->applyFromArray([
            'font' => ['bold'=>true,'color'=>['rgb'=>'FFFFFF'],'size'=>9,'name'=>'Arial'],
            'fill' => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'D97706']],
        ]);
        $ws->getStyle($L['sr'].$data_row)->getFont()->setBold(true)->setColor(new Color('92400E'));
    } else {
        $ws->getStyle($L['type'].$data_row)->getFont()->setColor(new Color('6B7280'));
    }

    /* Status colour */
    $sc = $STATUS_COLORS[$d['_st']] ?? null;
    if ($sc) {
        $ws->getStyle($L['status'].$data_row)->applyFromArray([
            'font' => ['bold'=>true,'color'=>['rgb'=>$sc['fg']],'size'=>9,'name'=>'Arial'],
            'fill' => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$sc['bg']]],
        ]);
    }

    /* Cheque No bold */
    $ws->getStyle($L['cheque_no'].$data_row)->getFont()->setBold(true)->setColor(new Color('3730A3'));

    /* Verified green */
    if ($d['verified'] === 'Yes') {
        $ws->getStyle($L['verified'].$data_row)->getFont()->setBold(true)->setColor(new Color('166534'));
    }

    $ws->getRowDimension($data_row)->setRowHeight(17);
    $data_row++;
}
$last_data = $data_row - 1;

/* ── Sampath subtotal row (only when export mixes normal + Sampath) ── */
$amt_col   = $L['amount'];
$before_amt = Coordinate::stringFromColumnIndex(Coordinate::columnIndexFromString($amt_col) - 1);
$after_amt  = Coordinate::stringFromColumnIndex(Coordinate::columnIndexFromString($amt_col) + 1);

if ($samp_count > 0 && !$f_sampath_only) {
    $sr_row = $data_row++;
    $ws->setCellValue("A{$sr_row}", 'SAMPATH CHEQUES SUBTOTAL');
    $ws->mergeCells("A{$sr_row}:{$before_amt}{$sr_row}");
    $ws->setCellValue("{$amt_col}{$sr_row}", $samp_total);
    $ws->setCellValue("{$after_amt}{$sr_row}", $samp_count.' cheques');
    $ws->mergeCells("{$after_amt}{$sr_row}:{$LAST}{$sr_row}");
    $ws->getStyle("A{$sr_row}:{$LAST}{$sr_row}")->applyFromArray([
        'font'      => ['bold'=>true,'size'=>10,'color'=>['rgb'=>'92400E'],'name'=>'Arial'],
        'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'FDE68A']],
        'alignment' => ['vertical'=>Alignment::VERTICAL_CENTER],
    ]);
    $ws->getStyle("A{$sr_row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $ws->getStyle("{$amt_col}{$sr_row}")->getNumberFormat()->setFormatCode('#,##0.00');
    $ws->getStyle("{$amt_col}{$sr_row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $ws->getRowDimension($sr_row)->setRowHeight(19);
}

/* ── Footer / totals row ── */
$total_row = $data_row;
$ws->setCellValue("A{$total_row}", 'TOTAL');
$ws->mergeCells("A{$total_row}:{$before_amt}{$total_row}");
$ws->setCellValue("{$amt_col}{$total_row}", $grand_total);
$ws->setCellValue("{$after_amt}{$total_row}", count($rows).' cheques');
$ws->mergeCells("{$after_amt}{$total_row}:{$LAST}{$total_row}");

$ws->getStyle("A{$total_row}:{$LAST}{$total_row}")->applyFromArray([
    'font'      => ['bold'=>true,'size'=>10,'color'=>['rgb'=>$C_TOTAL_FG],'name'=>'Arial'],
    'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$C_TOTAL_BG]],
    'alignment' => ['vertical'=>Alignment::VERTICAL_CENTER],
]);
$ws->getStyle("A{$total_row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$ws->getStyle("{$amt_col}{$total_row}")->getNumberFormat()->setFormatCode('#,##0.00');
$ws->getStyle("{$amt_col}{$total_row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$ws->getStyle("{$after_amt}{$total_row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
$ws->getRowDimension($total_row)->setRowHeight(20);

/* ── Freeze panes at row 4 (data starts) ── */
$ws->freezePane('A4');

/* ── Auto-filter on header row ── */
if ($last_data >= 4) {
    $ws->setAutoFilter("A3:{$LAST}{$last_data}");
}

/* ── Outer border around data block ── */
$ws->getStyle("A3:{$LAST}{$total_row}")->applyFromArray([
    'borders' => [
        'outline' => ['borderStyle'=>Border::BORDER_MEDIUM,'color'=>['rgb'=>'1E1B4B']],
    ]
]);

/* ── Output ── */
$filename = 'cheques_'.($f_sampath_only ? 'sampath_' : '').date('Ymd_Hi').'.xlsx';

if (ob_get_length()) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Cache-Control: max-age=0');
header('Pragma: public');

$writer = new Xlsx($wb);
$writer->save('php://output');
exit;
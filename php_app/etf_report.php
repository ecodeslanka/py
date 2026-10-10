<?php
/**
 * ETF REPORT PAGE  (ETF only — 3% employer contribution)
 * TXT format matches the DU/HU fixed-width layout from EPF submissions.
 * Columns: NIC/Passport | Surname | Initials | Member No | Total Contribution
 *          Employer No  | From Period | To Period
 */
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'config.php';

// ── Auto-create ETF tables ────────────────────────────────────────────────────
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS etf_batches (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        payroll_period_id INT NOT NULL,
        batch_label       VARCHAR(20),
        submission_no     INT DEFAULT 1,
        total_members     INT           NOT NULL DEFAULT 0,
        total_etf         DECIMAL(14,2) NOT NULL DEFAULT 0,
        status            VARCHAR(20)   DEFAULT 'Pending',
        notes             TEXT,
        created_by        INT,
        created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_period (payroll_period_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS etf_batch_entries (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        batch_id       INT NOT NULL,
        employee_id    INT NOT NULL,
        epf_number     VARCHAR(20),
        nic            VARCHAR(20),
        full_name      VARCHAR(200),
        surname        VARCHAR(100),
        initials       VARCHAR(50),
        basic          DECIMAL(12,2) DEFAULT 0,
        no_pay         DECIMAL(12,2) DEFAULT 0,
        basic_adjusted DECIMAL(12,2) DEFAULT 0,
        etf_er         DECIMAL(12,2) DEFAULT 0,
        att_days       INT           DEFAULT 0,
        source         VARCHAR(10)   DEFAULT 'live',
        FOREIGN KEY (batch_id) REFERENCES etf_batches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
// Safety-net migration in case etf_batch_entries already existed on this
// install without the No Pay / Basic Adjusted Salary columns.
foreach ([
    "ALTER TABLE etf_batch_entries ADD COLUMN IF NOT EXISTS no_pay DECIMAL(12,2) DEFAULT 0",
    "ALTER TABLE etf_batch_entries ADD COLUMN IF NOT EXISTS basic_adjusted DECIMAL(12,2) DEFAULT 0",
] as $mig) @mysqli_query($conn, $mig);
// Payment slips table (ETF)
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS etf_batch_payment_slips (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        batch_id    INT NOT NULL,
        slip_date   DATE,
        file_name   VARCHAR(255),
        file_path   VARCHAR(500),
        file_size   INT,
        notes       TEXT,
        uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_batch (batch_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Ensure uploads dir
$upload_dir = __DIR__ . '/uploads/etf_slips/';
if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);

// ── Helpers ───────────────────────────────────────────────────────────────────
function deriveEtfNameParts(string $fullName): array {
    $words = preg_split('/\s+/', trim(strtoupper($fullName)));
    $words = array_values(array_filter($words));
    if (!$words) return ['', ''];
    if (count($words) === 1) return [$words[0], ''];
    $surname  = array_pop($words);
    $initials = implode(' ', array_map(fn($w) => $w[0], $words));
    return [$surname, $initials];
}

/**
 * Build ETF TXT line matching DU format from EPF_APR_2026.txt:
 * DU {employer_no(9)} {member_no(6)} {initials(padded)} {surname(padded)} {NIC(12 or 9+V)} {from_period(6)} {to_period(6)} {amount_cents(9)}
 *
 * Exact fixed-width layout reverse-engineered from sample:
 * Pos  1- 2  : "DU"
 * Pos  3-11  : employer_no  (9 chars, zero-padded left? — from sample: "013409")
 *              actually sample shows "DU 013409" with a space → "DU " + 6-char employer number + "000"
 *              Let's match exactly: "DU " + employer_no padded to 9 + member_no padded to 6
 * Looking at sample line more carefully:
 * "DU 013409000001P P C               RANATHUNGA                      882451038V202604202604000090000"
 *  --+------+------+-----------------+--------------------------------+----------+------+------+---------
 *  2  space  6emp   6member  initials(17 approx)  surname(32)         NIC         from   to    amount(9)
 *
 * After careful analysis:
 *  [0..1]   "DU"
 *  [2]      " "
 *  [3..8]   employer number (6 chars)
 *  [9..14]  member number (6 chars, zero-padded)
 *  [15..30] initials (left-aligned, space-padded to 16 chars)  e.g. "P P C           "
 *  [31..62] surname  (left-aligned, space-padded to 32 chars)  e.g. "RANATHUNGA                      "
 *  [63..74] NIC      (12 chars — old NIC right-justified in 12, new NIC as 12-digit)
 *  [75..80] from period (6 chars) e.g. "202604"
 *  [81..86] to period   (6 chars) e.g. "202604"
 *  [87..95] amount in cents (9 chars, zero-padded) e.g. "000090000"
 */
function buildEtfTxtLine(
    string $employerNo,
    int    $memberNo,
    string $initials,
    string $surname,
    string $nic,
    string $period,   // YYYYMM
    float  $amount    // amount
): string {
    // NIC: if 12-digit numeric → pad to 12; old NIC (9+V/X) → pad left with spaces to 12
    $nicClean = strtoupper(trim($nic));
    if (strlen($nicClean) === 12 && ctype_digit($nicClean)) {
        $nicFmt = $nicClean; // already 12
    } else {
        $nicFmt = str_pad($nicClean, 12, ' ', STR_PAD_LEFT); // right-align old NIC in 12
    }

    // Amount: multiply by 100 to get cents, no decimal, zero-pad to 9
    $cents = str_pad((string)round($amount * 100), 9, '0', STR_PAD_LEFT);

    $line =
        'DU ' .
        str_pad(substr($employerNo, 0, 6), 6) .
        str_pad((string)$memberNo, 6, '0', STR_PAD_LEFT) .
        str_pad(strtoupper($initials), 16) .
        str_pad(strtoupper($surname),  32) .
        $nicFmt .
        $period .
        $period .
        $cents;

    return $line . "\r\n";
}

// ── ETF / EPF Settings ────────────────────────────────────────────────────────
$epf_settings   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM epf_etf_settings LIMIT 1"));
$etf_rate       = $epf_settings ? floatval($epf_settings['etf_employer'] ?? 3) : 3;
$employer_number= $epf_settings['employer_number'] ?? '';

// ── Payroll Periods ───────────────────────────────────────────────────────────
$all_periods = [];
$pp_res = mysqli_query($conn, "SELECT id, year, month, status FROM payroll_periods ORDER BY year DESC, month DESC");
if ($pp_res) while ($p = mysqli_fetch_assoc($pp_res)) $all_periods[] = $p;

$sel_period_id = isset($_GET['period_id']) ? intval($_GET['period_id']) : 0;
if (!$sel_period_id) {
    foreach ($all_periods as $pp) {
        if (($pp['status'] ?? '') === 'Open') { $sel_period_id = $pp['id']; break; }
    }
    if (!$sel_period_id && !empty($all_periods)) $sel_period_id = $all_periods[0]['id'];
}
$active_period = null;
foreach ($all_periods as $pp) {
    if ($pp['id'] == $sel_period_id) { $active_period = $pp; break; }
}
$month_names    = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
$contrib_period = $active_period ? sprintf('%04d%02d', $active_period['year'], $active_period['month']) : date('Ym');

// ── Filters ───────────────────────────────────────────────────────────────────
$filter_company = !empty($_GET['filter_company']) ? intval($_GET['filter_company']) : 0;
$filter_branch  = !empty($_GET['filter_branch'])  ? intval($_GET['filter_branch'])  : 0;
$search_q       = !empty($_GET['q']) ? mysqli_real_escape_string($conn, trim($_GET['q'])) : '';

// ── Employees ─────────────────────────────────────────────────────────────────
$where = "e.status = 'Permanent' AND e.epf_number IS NOT NULL AND e.epf_number != '' AND e.active = 1";
if ($filter_company) $where .= " AND e.company_id = $filter_company";
if ($filter_branch)  $where .= " AND e.branch_id = $filter_branch";
if ($search_q)       $where .= " AND (e.employee_id LIKE '%$search_q%' OR e.employee_full_name LIKE '%$search_q%' OR e.epf_number LIKE '%$search_q%')";

$emp_res = mysqli_query($conn, "
    SELECT e.id, e.employee_id, e.employee_full_name, e.name_with_initials,
           e.epf_number, e.id_number, e.date_of_join, e.basic_salary,
           c.company_name, c.company_code,
           b.branch_name, b.branch_code,
           d.designation_name
    FROM employees e
    LEFT JOIN companies c        ON e.company_id = c.id
    LEFT JOIN branches b         ON e.branch_id = b.id
    LEFT JOIN designations d     ON e.designation_id = d.id
    WHERE $where
    ORDER BY c.company_code, CAST(e.epf_number AS UNSIGNED), e.employee_full_name
");
$employees = [];
while ($row = mysqli_fetch_assoc($emp_res)) $employees[] = $row;

// ── Sheet data (basic + no_pay_amount + att_days from salary_sheet_entries) ──
// NOTE: etf_er is now always DERIVED here from Basic Adjusted Salary (see
// below), never read from a saved etf_er snapshot column — a saved figure
// could have been computed against raw Basic before No Pay was known/applied,
// which would silently misstate the employer's actual ETF liability.
$sheet_data = [];
if ($sel_period_id && !empty($employees)) {
    $ids_str = implode(',', array_map(fn($e) => (int)$e['id'], $employees));
    // Check if no_pay_amount / att_days columns exist
    $col_chk1 = mysqli_query($conn, "SHOW COLUMNS FROM salary_sheet_entries LIKE 'no_pay_amount'");
    $has_np   = $col_chk1 && mysqli_num_rows($col_chk1) > 0;
    $col_chk2 = mysqli_query($conn, "SHOW COLUMNS FROM salary_sheet_entries LIKE 'att_days'");
    $has_att  = $col_chk2 && mysqli_num_rows($col_chk2) > 0;
    $np_col   = $has_np  ? ', no_pay_amount' : '';
    $att_col  = $has_att ? ', att_days' : '';
    $sh = mysqli_query($conn,
        "SELECT employee_id, basic $np_col $att_col
         FROM salary_sheet_entries
         WHERE payroll_period_id = $sel_period_id AND employee_id IN ($ids_str)");
    if ($sh) while ($sr = mysqli_fetch_assoc($sh)) $sheet_data[(int)$sr['employee_id']] = $sr;
}

// ── Per-employee ETF rows ─────────────────────────────────────────────────────
// Basic Adjusted Salary = Basic Salary − No Pay. ETF (employer contribution)
// is calculated on this ADJUSTED figure, not raw Basic, so No Pay days are
// correctly excluded from the contribution base.
$rows   = [];
$totals = ['basic'=>0,'no_pay'=>0,'basic_adjusted'=>0,'etf_er'=>0];
foreach ($employees as $emp) {
    $eid = (int)$emp['id'];
    if (isset($sheet_data[$eid])) {
        $basic  = floatval($sheet_data[$eid]['basic']);
        $no_pay = floatval($sheet_data[$eid]['no_pay_amount'] ?? 0);
        $att_days = intval($sheet_data[$eid]['att_days'] ?? 0);
        $source = 'sheet';
    } else {
        $basic    = floatval($emp['basic_salary'] ?? 0);
        $no_pay   = 0.0;
        $att_days = 0;
        $source   = 'live';
    }
    $basic_adjusted = round($basic - $no_pay, 2);
    $etf_er         = round($basic_adjusted * $etf_rate / 100, 2);
    [$surname, $initials] = deriveEtfNameParts($emp['employee_full_name']);
    $rows[$eid]              = compact('basic','no_pay','basic_adjusted','etf_er','source','att_days','surname','initials');
    $totals['basic']         += $basic;
    $totals['no_pay']        += $no_pay;
    $totals['basic_adjusted']+= $basic_adjusted;
    $totals['etf_er']        += $etf_er;
}

// ── Existing batch for this period ────────────────────────────────────────────
$existing_batch = null;
if ($sel_period_id) {
    $bchk = mysqli_query($conn, "SELECT * FROM etf_batches WHERE payroll_period_id = $sel_period_id LIMIT 1");
    if ($bchk && mysqli_num_rows($bchk) > 0) $existing_batch = mysqli_fetch_assoc($bchk);
}

// ── POST: Save Batch ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_etf_batch') {
    ob_clean();
    header('Content-Type: application/json');
    $pid = intval($_POST['period_id'] ?? 0);
    if (!$pid) { echo json_encode(['success'=>false,'msg'=>'Invalid period']); exit; }

    $period_row  = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT year, month FROM payroll_periods WHERE id = $pid LIMIT 1"));
    $batch_label = $period_row ? sprintf('%04d-%02d', $period_row['year'], $period_row['month']) : date('Y-m');

    $entries      = json_decode($_POST['entries'] ?? '[]', true);
    $total_members= count($entries);
    $t_etf        = floatval($_POST['total_etf'] ?? 0);

    // Remove existing
    $old = mysqli_query($conn, "SELECT id FROM etf_batches WHERE payroll_period_id = $pid LIMIT 1");
    if ($old && mysqli_num_rows($old) > 0) {
        $o = mysqli_fetch_assoc($old);
        mysqli_query($conn, "DELETE FROM etf_batches WHERE id = " . intval($o['id']));
    }

    $bl = mysqli_real_escape_string($conn, $batch_label);
    $ok = mysqli_query($conn, "
        INSERT INTO etf_batches (payroll_period_id, batch_label, submission_no, total_members, total_etf, status, created_at)
        VALUES ($pid, '$bl', 1, $total_members, $t_etf, 'Pending', NOW())
    ");
    if (!$ok) { echo json_encode(['success'=>false,'msg'=>'DB error: '.mysqli_error($conn)]); exit; }

    $bid = mysqli_insert_id($conn);
    $failed = 0;
    foreach ($entries as $e) {
        $eid_e  = intval($e['employee_id']);
        $epf_no = mysqli_real_escape_string($conn, $e['epf_number'] ?? '');
        $nic_e  = mysqli_real_escape_string($conn, $e['nic']        ?? '');
        $fname  = mysqli_real_escape_string($conn, $e['full_name']  ?? '');
        $sname  = mysqli_real_escape_string($conn, $e['surname']    ?? '');
        $init   = mysqli_real_escape_string($conn, $e['initials']   ?? '');
        $bas_e  = floatval($e['basic']    ?? 0);
        $np_e   = floatval($e['no_pay']   ?? 0);
        $badj_e = floatval($e['basic_adjusted'] ?? ($bas_e - $np_e));
        $etf_e  = floatval($e['etf_er']  ?? 0);
        $att_e  = intval($e['att_days']  ?? 0);
        $src_e  = mysqli_real_escape_string($conn, $e['source']     ?? 'live');
        $r = mysqli_query($conn, "
            INSERT INTO etf_batch_entries
                (batch_id, employee_id, epf_number, nic, full_name, surname, initials, basic, no_pay, basic_adjusted, etf_er, att_days, source)
            VALUES ($bid, $eid_e, '$epf_no', '$nic_e', '$fname', '$sname', '$init', $bas_e, $np_e, $badj_e, $etf_e, $att_e, '$src_e')
        ");
        if (!$r) $failed++;
    }
    if ($failed > 0) {
        echo json_encode(['success'=>false,'msg'=>"$failed entries failed: ".mysqli_error($conn)]); exit;
    }
    echo json_encode(['success'=>true,'batch_id'=>$bid,'msg'=>'ETF Batch saved successfully!']);
    exit;
}

// ── TXT download ───────────────────────────────────────────────────────────────
if (isset($_GET['download']) && $_GET['download'] === 'txt') {
    ob_clean();
    $lbl = $active_period ? $month_names[$active_period['month']].'_'.$active_period['year'] : 'export';
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="ETF_'.$lbl.'.txt"');

    $total_amount_cents = 0;
    $lines = [];
    foreach ($employees as $emp) {
        $eid = (int)$emp['id'];
        $r   = $rows[$eid];
        // Amount submitted is always ETF calculated on Basic Adjusted Salary (basic − no pay).
        $line = buildEtfTxtLine(
            $employer_number,
            (int)$emp['epf_number'],
            $r['initials'],
            $r['surname'],
            strtoupper(trim($emp['id_number'] ?? '')),
            $contrib_period,
            $r['etf_er']
        );
        $lines[] = $line;
        $total_amount_cents += round($r['etf_er'] * 100);
    }
    foreach ($lines as $l) echo $l;

    // HU trailer line
    $emp_count    = count($employees);
    $total_str    = str_pad((string)$total_amount_cents, 9, '0', STR_PAD_LEFT);
    $count_str    = str_pad((string)$emp_count, 6, '0', STR_PAD_LEFT);
    echo 'HU ' . str_pad(substr($employer_number,0,6),6) . $contrib_period . substr($contrib_period,0,4) . '0' . $total_str . $count_str . "\r\n";
    exit;
}

// ── Minimal dependency-free XLSX writer (ZipArchive only — no python3/openpyxl
//    needed, so this can never fail due to a missing external interpreter or
//    package on the server; this is the same approach already proven working
//    in salary_excel_generate.php) ──────────────────────────────────────────
class EtfXlsxWriter {
    private $rows = []; private $merges = []; private $widths = [];
    const S_DEFAULT=0; const S_TITLE=1; const S_SUB=2; const S_HEADER=3; const S_NUM=4; const S_NUM_BOLD=5; const S_TEXT_BOLD=6;
    public function setWidths(array $w){ $this->widths = $w; }
    public function merge($ref){ $this->merges[] = $ref; }
    public function addRow(array $cells){ $this->rows[] = $cells; }
    private function colLetter($i){ $i++; $s=''; while($i>0){ $m=($i-1)%26; $s=chr(65+$m).$s; $i=intdiv($i-1,26);} return $s; }
    private function esc($s){
        $s=(string)$s;
        if (function_exists('mb_check_encoding') && !mb_check_encoding($s,'UTF-8')) {
            $s = function_exists('mb_convert_encoding') ? mb_convert_encoding($s,'UTF-8','UTF-8') : preg_replace('/[\x80-\xFF]/','',$s);
        }
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
        return str_replace(['&','<','>'], ['&amp;','&lt;','&gt;'], $s);
    }
    private function sheetXml(){
        $out = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $out .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="3" topLeftCell="A4" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        if (!empty($this->widths)) {
            $out .= '<cols>';
            foreach ($this->widths as $i=>$w) $out .= '<col min="'.($i+1).'" max="'.($i+1).'" width="'.$w.'" customWidth="1"/>';
            $out .= '</cols>';
        }
        $out .= '<sheetData>';
        foreach ($this->rows as $rIdx=>$row) {
            $rNum = $rIdx+1;
            $out .= '<row r="'.$rNum.'">';
            foreach ($row as $cIdx=>$cell) {
                [$val,$type,$style] = $cell;
                $ref = $this->colLetter($cIdx).$rNum;
                $sAttr = $style ? ' s="'.$style.'"' : '';
                if ($type==='s') {
                    $out .= '<c r="'.$ref.'"'.$sAttr.' t="inlineStr"><is><t xml:space="preserve">'.$this->esc($val).'</t></is></c>';
                } else {
                    $v = is_numeric($val) ? (float)$val : 0.0;
                    $vStr = rtrim(rtrim(sprintf('%.6F',$v),'0'),'.');
                    if ($vStr==='' || $vStr==='-') $vStr='0';
                    $out .= '<c r="'.$ref.'"'.$sAttr.'><v>'.$vStr.'</v></c>';
                }
            }
            $out .= '</row>';
        }
        $out .= '</sheetData>';
        if (!empty($this->merges)) {
            $out .= '<mergeCells count="'.count($this->merges).'">';
            foreach ($this->merges as $m) $out .= '<mergeCell ref="'.$m.'"/>';
            $out .= '</mergeCells>';
        }
        $out .= '</worksheet>';
        return $out;
    }
    private function stylesXml(){
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
            . '<fonts count="4">'
            . '<font><sz val="10"/><name val="Arial"/></font>'
            . '<font><sz val="14"/><b/><color rgb="FFFFFFFF"/><name val="Arial"/></font>'
            . '<font><sz val="9"/><b/><color rgb="FFFFFFFF"/><name val="Arial"/></font>'
            . '<font><sz val="10"/><b/><name val="Arial"/></font>'
            . '</fonts>'
            . '<fills count="4">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF0D9488"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF0FDFA"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="7">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                                                                                    // 0 default
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 1 title
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'          // 2 subtitle
            . '<xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' // 3 header
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'                                                            // 4 number
            . '<xf numFmtId="164" fontId="3" fillId="3" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"/>'                                // 5 number bold (totals)
            . '<xf numFmtId="0" fontId="3" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="right"/></xf>'  // 6 text bold (totals label)
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }
    public function output($filename){
        while (ob_get_level() > 0) { @ob_end_clean(); }
        @ini_set('display_errors','0');
        if (!class_exists('ZipArchive')) {
            http_response_code(500); header('Content-Type: text/plain; charset=utf-8');
            echo 'The PHP "zip" extension is not enabled on this server, so the Excel file cannot be built. Please ask your host to enable php-zip.'; exit;
        }
        $tmp = tempnam(sys_get_temp_dir(),'etfxlsx'); @unlink($tmp);
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE) !== true) {
            @unlink($tmp);
            http_response_code(500); header('Content-Type: text/plain; charset=utf-8');
            echo 'Could not create the Excel file. Please try again.'; exit;
        }
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>';
        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="ETF Report" sheetId="1" r:id="rId1"/></sheets></workbook>';
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rootRels);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheetXml());
        $zip->close();
        clearstatcache(true, $tmp);
        if (!file_exists($tmp) || filesize($tmp) === 0) {
            @unlink($tmp);
            http_response_code(500); header('Content-Type: text/plain; charset=utf-8');
            echo 'The Excel file came out empty when building it. Please try again.'; exit;
        }
        while (ob_get_level() > 0) { @ob_end_clean(); }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Content-Length: '.filesize($tmp));
        header('Cache-Control: max-age=0');
        readfile($tmp); unlink($tmp); exit;
    }
}
function etfColRef($i){ $i++; $s=''; while($i>0){ $m=($i-1)%26; $s=chr(65+$m).$s; $i=intdiv($i-1,26);} return $s; }

// ── Excel download ─────────────────────────────────────────────────────────────
if (isset($_GET['download']) && $_GET['download'] === 'excel') {
    ob_clean();
    $lbl = $active_period ? $month_names[$active_period['month']].'_'.$active_period['year'] : 'export';

    $COLS = [
        ['label'=>'#',                     'w'=>4,  'type'=>'n'],
        ['label'=>'NIC / Passport',        'w'=>16, 'type'=>'s'],
        ['label'=>'Surname',               'w'=>22, 'type'=>'s'],
        ['label'=>'Initials',              'w'=>12, 'type'=>'s'],
        ['label'=>'EPF No.',               'w'=>10, 'type'=>'s'],
        ['label'=>'Employee Name',         'w'=>28, 'type'=>'s'],
        ['label'=>'Code',                  'w'=>10, 'type'=>'s'],
        ['label'=>'Company',               'w'=>24, 'type'=>'s'],
        ['label'=>'Designation',           'w'=>20, 'type'=>'s'],
        ['label'=>'Joined',                'w'=>12, 'type'=>'s'],
        ['label'=>'Basic Salary',          'w'=>14, 'type'=>'n'],
        ['label'=>'No Pay',                'w'=>12, 'type'=>'n'],
        ['label'=>'Basic Adjusted Salary', 'w'=>16, 'type'=>'n'],
        ['label'=>"ETF {$etf_rate}%",      'w'=>14, 'type'=>'n'],
    ];
    $ncols = count($COLS);

    $xl = new EtfXlsxWriter();
    $xl->setWidths(array_column($COLS,'w'));

    $xl->addRow([[ 'ETF CONTRIBUTION REPORT', 's', EtfXlsxWriter::S_TITLE ]]);
    $xl->merge('A1:'.etfColRef($ncols-1).'1');
    $periodLbl = $active_period ? $month_names[$active_period['month']].' '.$active_period['year'] : 'N/A';
    $xl->addRow([[ "Period: $periodLbl | ETF Employer: {$etf_rate}% (on Basic Adjusted Salary) | Employer No: $employer_number", 's', EtfXlsxWriter::S_SUB ]]);
    $xl->merge('A2:'.etfColRef($ncols-1).'2');

    $hdr = [];
    foreach ($COLS as $c) $hdr[] = [$c['label'], 's', EtfXlsxWriter::S_HEADER];
    $xl->addRow($hdr);

    $n = 0;
    foreach ($employees as $emp) {
        $eid = (int)$emp['id']; $r = $rows[$eid]; $n++;
        $xl->addRow([
            [$n, 'n', EtfXlsxWriter::S_DEFAULT],
            [strtoupper(trim($emp['id_number']??'')), 's', EtfXlsxWriter::S_DEFAULT],
            [$r['surname'], 's', EtfXlsxWriter::S_DEFAULT],
            [$r['initials'], 's', EtfXlsxWriter::S_DEFAULT],
            [$emp['epf_number'], 's', EtfXlsxWriter::S_DEFAULT],
            [$emp['employee_full_name'], 's', EtfXlsxWriter::S_DEFAULT],
            [$emp['employee_id'], 's', EtfXlsxWriter::S_DEFAULT],
            [trim(($emp['company_code']??'').' - '.($emp['company_name']??''), ' -'), 's', EtfXlsxWriter::S_DEFAULT],
            [$emp['designation_name'] ?? '', 's', EtfXlsxWriter::S_DEFAULT],
            [$emp['date_of_join'] ? date('d/m/Y', strtotime($emp['date_of_join'])) : '', 's', EtfXlsxWriter::S_DEFAULT],
            [$r['basic'], 'n', EtfXlsxWriter::S_NUM],
            [$r['no_pay'], 'n', EtfXlsxWriter::S_NUM],
            [$r['basic_adjusted'], 'n', EtfXlsxWriter::S_NUM],
            [$r['etf_er'], 'n', EtfXlsxWriter::S_NUM],
        ]);
    }

    $totRow = [];
    foreach ($COLS as $idx0 => $c) {
        if ($idx0 === 0) { $totRow[] = ["TOTALS ($n employees)", 's', EtfXlsxWriter::S_TEXT_BOLD]; continue; }
        if ($idx0 < 10) { $totRow[] = ['', 's', EtfXlsxWriter::S_TEXT_BOLD]; continue; }
        $key = ['basic','no_pay','basic_adjusted','etf_er'][$idx0-10];
        $totRow[] = [round($totals[$key],2), 'n', EtfXlsxWriter::S_NUM_BOLD];
    }
    $xl->addRow($totRow);
    $xl->merge('A'.($n+4).':J'.($n+4));

    $xl->output('ETF_Report_'.$lbl.'.xlsx');
}

// ── Filter dropdowns ──────────────────────────────────────────────────────────
$companies_res = mysqli_query($conn, "SELECT id, company_code, company_name FROM companies WHERE active=1 ORDER BY company_name");
$branches_res  = $filter_company
    ? mysqli_query($conn, "SELECT id, branch_code, branch_name FROM branches WHERE company_id=$filter_company AND active=1 ORDER BY branch_name")
    : null;

include 'header.php';
?>
<style>
:root{--tk:#0d9488;--tm:#14b8a6;--tl:#f0fdfa;--dk:#0c4a6e;--md:#0369a1;--lt:#e0f2fe;--gn:#166534;--gl:#dcfce7;--am:#92400e;--al:#fef3c7;--pu:#7c3aed;--pl:#f3e8ff;--bd:#e2e8f0;}
/* Header */
.ph{background:linear-gradient(135deg,var(--tk),var(--tm));color:#fff;padding:20px 26px 16px;border-radius:0 0 14px 14px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;}
.ph-t{font-size:20px;font-weight:800;display:flex;align-items:center;gap:9px;}
.ph-s{font-size:12px;color:#99f6e4;margin-top:3px;}
.ph-acts{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
/* Buttons */
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 14px;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;border:none;text-decoration:none;font-family:inherit;white-space:nowrap;transition:all .18s;}
.btn-back{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);}.btn-back:hover{background:rgba(255,255,255,.25);}
.btn-txt{background:#f0fdfa;color:#0f766e;border:1px solid #99f6e4;}.btn-txt:hover{background:#ccfbf1;}
.btn-xl{background:var(--gl);color:var(--gn);border:1px solid #86efac;}.btn-xl:hover{background:#bbf7d0;}
.btn-save{background:var(--tk);color:#fff;}.btn-save:hover{background:#0f766e;}
/* Summary cards */
.sc-row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;}
.sc{background:#fff;border:1px solid var(--bd);border-radius:11px;padding:13px 16px;flex:1;min-width:150px;position:relative;overflow:hidden;}
.sc::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;}
.sc.s-emp::before{background:var(--tk);}
.sc.s-np::before{background:#dc2626;}
.sc.s-etf::before{background:var(--pu);}
.sc.s-rt::before{background:var(--gn);}
.sc-lb{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:4px;}
.sc-vl{font-size:20px;font-weight:900;letter-spacing:-1px;}
.sc.s-emp .sc-vl{color:var(--tk);}
.sc.s-np  .sc-vl{color:#dc2626;font-size:15px;}
.sc.s-etf .sc-vl{color:var(--pu);font-size:15px;}
.sc.s-rt  .sc-vl{color:var(--gn);}
/* Toolbar */
.tb{background:#fff;border:1px solid var(--bd);border-radius:10px;padding:12px 16px;margin-bottom:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.tb select,.tb input{padding:7px 10px;border:1.5px solid var(--bd);border-radius:7px;font-size:12px;font-family:inherit;outline:none;color:#374151;}
.tb select:focus,.tb input:focus{border-color:var(--tk);}
.tb-sep{width:1px;height:24px;background:var(--bd);}
/* Table */
.tbl-wrap{background:#fff;border:1px solid var(--bd);border-radius:11px;overflow:hidden;}
.tbl-hdr{background:linear-gradient(135deg,var(--tk),var(--tm));color:#fff;padding:13px 18px;display:flex;align-items:center;justify-content:space-between;}
.tbl-title{font-weight:800;font-size:14px;display:flex;align-items:center;gap:8px;}
.tbl-sub{font-size:11px;color:#99f6e4;}
table{width:100%;border-collapse:collapse;font-size:12px;}
thead tr{background:#1e293b;}
thead th{padding:9px 10px;color:#94a3b8;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;border-right:1px solid #2d3748;}
thead th.tl{text-align:left;}thead th.tc{text-align:center;}thead th.tr{text-align:right;}
thead .th-id{background:#1e3a5f!important;color:#93c5fd;}
thead .th-np{background:#7f1d1d!important;color:#fca5a5;}
thead .th-badj{background:#3730a3!important;color:#c7d2fe;}
thead .th-etf{background:#134e4a!important;color:#5eead4;}
thead .th-tot{background:#4c1d95!important;color:#c4b5fd;}
tbody tr{border-bottom:1px solid #f1f5f9;transition:background .12s;}
tbody tr:hover{background:#f0fdfa;}
tbody td{padding:8px 10px;vertical-align:middle;}
td.tc{text-align:center;}td.tl{text-align:left;}td.tr{text-align:right;}
.nic-c{font-family:'Courier New',monospace;font-weight:700;font-size:11px;color:#0f766e;background:#f0fdfa;padding:2px 6px;border-radius:4px;border:1px solid #99f6e4;}
.et{font-family:'Courier New',monospace;font-weight:800;font-size:12px;color:#0369a1;background:#e0f2fe;padding:2px 7px;border-radius:4px;}
.sn{font-weight:800;color:#0c4a6e;font-family:'Courier New',monospace;font-size:12px;}
.en{font-weight:700;font-size:12px;color:#1e293b;}
.ei{font-size:10px;color:#64748b;font-style:italic;}
.ec{font-size:10px;color:#94a3b8;}
.m-np{font-family:'Courier New',monospace;font-weight:700;font-size:12px;color:#b91c1c;}
.m-badj{font-family:'Courier New',monospace;font-weight:800;font-size:12px;color:#3730a3;background:#eef2ff;padding:2px 7px;border-radius:4px;}
.m-etf{font-family:'Courier New',monospace;font-weight:800;font-size:13px;color:#0f766e;background:#f0fdfa;padding:2px 8px;border-radius:5px;border:1px solid #99f6e4;}
.m-z{color:#d1d5db;}
.ac{display:inline-block;padding:2px 7px;border-radius:4px;font-size:11px;font-weight:700;}
.ac.ok{background:#dcfce7;color:#166534;}
.ac.zz{background:#f1f5f9;color:#94a3b8;}
.bs{display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:700;}
.bs-s{background:#dcfce7;color:#166534;}.bs-l{background:#e0f2fe;color:#0369a1;}
.tr-tot{background:#1e293b!important;}
.tr-tot td{padding:10px;font-weight:800;border-bottom:none;}
/* Modal */
.mo{position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:9000;display:none;align-items:center;justify-content:center;padding:20px;}
.mo.open{display:flex;}
.mb{background:#fff;border-radius:14px;width:500px;max-width:96vw;box-shadow:0 20px 60px rgba(0,0,0,.28);overflow:hidden;}
.mh{background:linear-gradient(135deg,var(--tk),var(--tm));color:#fff;padding:18px 22px;display:flex;align-items:center;gap:12px;}
.mh h3{margin:0;font-size:15px;font-weight:800;}
.mbd{padding:18px 22px;}
.mrow{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;}
.mf{display:flex;flex-direction:column;gap:4px;margin-bottom:10px;}
.mf label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#64748b;}
.mf input,.mf textarea{padding:8px 10px;border:1.5px solid var(--bd);border-radius:7px;font-size:12px;font-family:inherit;outline:none;}
.mf input:focus,.mf textarea:focus{border-color:var(--tk);}
.mft{padding:14px 22px;border-top:1px solid var(--bd);display:flex;justify-content:flex-end;gap:8px;background:#f8fafc;}
.btn-cn{background:#f1f5f9;color:#374151;border:1px solid var(--bd);}.btn-cn:hover{background:#e2e8f0;}
.btn-ok{background:var(--tk);color:#fff;}.btn-ok:hover{background:#0f766e;}
/* Toast */
.toast{position:fixed;bottom:24px;right:24px;padding:12px 18px;border-radius:9px;font-size:13px;font-weight:700;z-index:9999;opacity:0;transform:translateY(8px);transition:all .3s;pointer-events:none;}
.toast.show{opacity:1;transform:translateY(0);}
.toast.t-s{background:#166534;color:#fff;}.toast.t-e{background:#991b1b;color:#fff;}
</style>

<!-- PAGE HEADER -->
<div class="ph">
    <div>
        <div class="ph-t"><i class="fa-solid fa-building-columns"></i> ETF Contribution Report</div>
        <div class="ph-s">
            Employee Trust Fund · Employer Contribution <?php echo $etf_rate;?>% of Basic Adjusted Salary · Employer No: <?php echo htmlspecialchars($employer_number ?: '(not set)');?>
        </div>
    </div>
    <div class="ph-acts">
        <a class="btn btn-back" href="etf_batches.php"><i class="fa-solid fa-layer-group"></i> ETF Batches</a>
        <?php if (!empty($employees)): ?>
        <a class="btn btn-txt" href="?period_id=<?php echo $sel_period_id;?>&download=txt<?php echo $filter_company?"&filter_company=$filter_company":'';?>"><i class="fa-solid fa-file-lines"></i> Download TXT</a>
        <a class="btn btn-xl" href="?period_id=<?php echo $sel_period_id;?>&download=excel<?php echo $filter_company?"&filter_company=$filter_company":'';?>"><i class="fa-solid fa-file-excel"></i> Download Excel</a>
        <button class="btn btn-save" onclick="openModal()"><i class="fa-solid fa-floppy-disk"></i> <?php echo $existing_batch ? 'Update Batch' : 'Save Batch'; ?></button>
        <?php endif; ?>
    </div>
</div>

<!-- SUMMARY CARDS -->
<div class="sc-row">
    <div class="sc s-emp">
        <div class="sc-lb"><i class="fa-solid fa-users"></i> Employees</div>
        <div class="sc-vl"><?php echo count($employees);?></div>
        <div style="font-size:11px;color:#64748b;margin-top:3px;">Permanent · EPF registered</div>
    </div>
    <div class="sc s-np">
        <div class="sc-lb"><i class="fa-solid fa-calendar-xmark"></i> Total No Pay</div>
        <div class="sc-vl"><?php echo number_format($totals['no_pay'],2);?></div>
        <div style="font-size:11px;color:#64748b;margin-top:3px;">Deducted before ETF calc</div>
    </div>
    <div class="sc s-etf">
        <div class="sc-lb"><i class="fa-solid fa-building-columns"></i> Total ETF</div>
        <div class="sc-vl"><?php echo number_format($totals['etf_er'],2);?></div>
        <div style="font-size:11px;color:#64748b;margin-top:3px;">Employer <?php echo $etf_rate;?>% of Basic Adjusted Salary</div>
    </div>
    <div class="sc s-rt">
        <div class="sc-lb"><i class="fa-solid fa-calendar-days"></i> Period</div>
        <div class="sc-vl" style="font-size:15px;"><?php echo $active_period ? $month_names[$active_period['month']].' '.$active_period['year'] : '—';?></div>
        <div style="font-size:11px;color:#64748b;margin-top:3px;">
            <?php echo $existing_batch ? '<span style="color:#166534;font-weight:700;"><i class="fa-solid fa-circle-check"></i> Batch saved</span>' : '<span style="color:#92400e;">No batch yet</span>'; ?>
        </div>
    </div>
</div>

<!-- TOOLBAR -->
<div class="tb">
    <form method="GET" style="display:contents;">
        <input type="hidden" name="period_id" value="">
        <select name="period_id" onchange="this.form.submit()" style="min-width:170px;">
            <?php foreach ($all_periods as $pp):
                $sel = $pp['id'] == $sel_period_id ? 'selected' : '';
                $st  = $pp['status'] === 'Open' ? ' ★' : '';
                echo "<option value=\"{$pp['id']}\" $sel>{$month_names[$pp['month']]} {$pp['year']}{$st}</option>";
            endforeach; ?>
        </select>
        <div class="tb-sep"></div>
        <?php if ($companies_res && mysqli_num_rows($companies_res) > 1): ?>
        <select name="filter_company" onchange="this.form.submit()">
            <option value="">All Companies</option>
            <?php mysqli_data_seek($companies_res,0); while($co=mysqli_fetch_assoc($companies_res)): ?>
            <option value="<?php echo $co['id'];?>" <?php echo $filter_company==$co['id']?'selected':'';?>><?php echo htmlspecialchars($co['company_code'].' — '.$co['company_name']);?></option>
            <?php endwhile; ?>
        </select>
        <?php endif; ?>
        <div class="tb-sep"></div>
        <input type="text" name="q" value="<?php echo htmlspecialchars($search_q);?>" placeholder="Search employee / EPF No…" style="width:200px;" oninput="lf(this.value)">
        <span style="font-size:11px;color:#94a3b8;margin-left:auto;"><?php echo $contrib_period;?></span>
    </form>
</div>

<!-- TABLE -->
<div class="tbl-wrap">
    <div class="tbl-hdr">
        <div>
            <div class="tbl-title"><i class="fa-solid fa-table"></i> ETF Contributions</div>
            <div class="tbl-sub"><?php echo count($employees);?> employees · <?php echo $active_period ? $month_names[$active_period['month']].' '.$active_period['year'] : '—';?></div>
        </div>
    </div>
    <?php if (empty($employees)): ?>
    <div style="text-align:center;padding:60px;color:#94a3b8;">
        <i class="fa-solid fa-users-slash" style="font-size:40px;display:block;margin-bottom:12px;color:#e2e8f0;"></i>
        No permanent employees with EPF numbers found.
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table>
        <thead>
        <tr>
            <th class="tc th-id" style="width:36px;">#</th>
            <th class="tc th-id">NIC / Passport</th>
            <th class="tl th-id">Surname</th>
            <th class="tc th-id">Initials</th>
            <th class="tc th-id">EPF No.</th>
            <th class="tl">Employee Name</th>
            <th class="tl">Company / Branch</th>
            <th class="tl">Designation</th>
            <th class="tr">Basic Salary</th>
            <th class="tr th-np">No Pay</th>
            <th class="tr th-badj">Basic Adjusted Salary</th>
            <th class="tr th-etf">ETF <?php echo $etf_rate;?>%</th>
            <th class="tc">Att. Days</th>
            <th class="tc">Source</th>
        </tr>
        </thead>
        <tbody id="etb">
        <?php $i=0; foreach ($employees as $emp):
            $eid = (int)$emp['id'];
            $r   = $rows[$eid];
            $i++;
            $ds  = strtolower($emp['employee_full_name'].' '.$emp['epf_number'].' '.($emp['id_number']??'').' '.($emp['company_code']??''));
        ?>
        <tr data-s="<?php echo htmlspecialchars($ds);?>">
            <td class="tc" style="color:#94a3b8;font-size:11px;"><?php echo $i;?></td>
            <td class="tc"><?php $nd=strtoupper(trim($emp['id_number']??''));echo $nd?'<span class="nic-c">'.htmlspecialchars($nd).'</span>':'<span class="m-z">—</span>';?></td>
            <td class="tl"><div class="sn"><?php echo htmlspecialchars($r['surname']);?></div></td>
            <td class="tc"><span style="font-family:'Courier New',monospace;font-weight:700;font-size:12px;color:#0f766e;letter-spacing:1px;"><?php echo htmlspecialchars($r['initials']);?></span></td>
            <td class="tc"><span class="et"><?php echo htmlspecialchars($emp['epf_number']);?></span></td>
            <td class="tl">
                <div class="en"><?php echo htmlspecialchars($emp['employee_full_name']);?></div>
                <?php if($emp['name_with_initials']):?><div class="ei"><?php echo htmlspecialchars($emp['name_with_initials']);?></div><?php endif;?>
                <div class="ec"><?php echo htmlspecialchars($emp['employee_id']);?></div>
            </td>
            <td class="tl">
                <div style="font-size:11px;color:#374151;"><?php echo htmlspecialchars(($emp['company_code']??'').($emp['company_name']?' — '.$emp['company_name']:''));?></div>
                <?php if($emp['branch_name']):?><div style="font-size:10px;color:#94a3b8;"><?php echo htmlspecialchars($emp['branch_name']);?></div><?php endif;?>
            </td>
            <td class="tl"><div style="font-size:11px;color:#374151;"><?php echo htmlspecialchars($emp['designation_name']??'—');?></div></td>
            <td class="tr"><?php echo $r['basic']>0?'<span style="font-family:Courier New,monospace;font-size:12px;color:#374151;">'.number_format($r['basic'],2).'</span>':'<span class="m-z">—</span>';?></td>
            <td class="tr"><?php echo $r['no_pay']>0?'<span class="m-np">'.number_format($r['no_pay'],2).'</span>':'<span class="m-z">—</span>';?></td>
            <td class="tr"><span class="m-badj"><?php echo number_format($r['basic_adjusted'],2);?></span></td>
            <td class="tr"><?php echo $r['etf_er']>0?'<span class="m-etf">'.number_format($r['etf_er'],2).'</span>':'<span class="m-z">—</span>';?></td>
            <td class="tc"><span class="ac <?php echo $r['att_days']>0?'ok':'zz';?>"><?php echo $r['att_days']>0?$r['att_days']:'—';?></span></td>
            <td class="tc">
                <?php if($r['source']==='sheet'):?><span class="bs bs-s"><i class="fa-solid fa-circle-check"></i> Sheet</span>
                <?php else:?><span class="bs bs-l"><i class="fa-solid fa-rotate"></i> Live</span><?php endif;?>
            </td>
        </tr>
        <?php endforeach;?>
        </tbody>
        <tbody>
        <tr class="tr-tot">
            <td colspan="8" style="text-align:right;color:#5eead4;font-size:11px;"><i class="fa-solid fa-sigma" style="margin-right:5px;"></i>GRAND TOTAL (<?php echo count($employees);?> employees)</td>
            <td style="color:#e2e8f0;font-family:'Courier New',monospace;font-size:12px;font-weight:800;"><?php echo number_format($totals['basic'],2);?></td>
            <td style="color:#fca5a5;font-family:'Courier New',monospace;font-size:12px;font-weight:800;"><?php echo number_format($totals['no_pay'],2);?></td>
            <td style="color:#c7d2fe;font-family:'Courier New',monospace;font-size:12px;font-weight:800;"><?php echo number_format($totals['basic_adjusted'],2);?></td>
            <td style="color:#99f6e4;font-family:'Courier New',monospace;font-size:13px;font-weight:900;"><?php echo number_format($totals['etf_er'],2);?></td>
            <td colspan="2"></td>
        </tr>
        </tbody>
    </table>
    </div>
    <div id="nr" style="text-align:center;padding:38px;color:#94a3b8;display:none;"><i class="fa-solid fa-magnifying-glass" style="font-size:22px;margin-bottom:7px;display:block;"></i>No results match.</div>
    <?php endif;?>
</div>

<!-- TXT FORMAT INFO -->
<div style="margin-top:14px;background:#fff;border:1px solid #e2e8f0;border-radius:9px;padding:13px 16px;font-size:12px;color:#475569;">
    <div style="font-weight:800;color:#0f766e;margin-bottom:7px;"><i class="fa-solid fa-file-lines"></i> ETF TXT Format — <?php echo $contrib_period;?></div>
    <div style="display:flex;gap:18px;flex-wrap:wrap;">
        <span><strong>DU</strong> prefix</span>
        <span><strong>Employer No</strong> 6 chars · <?php echo htmlspecialchars($employer_number ?: '(set in settings)');?></span>
        <span><strong>Member No</strong> 6 chars, zero-padded</span>
        <span><strong>Initials</strong> 16 chars</span>
        <span><strong>Surname</strong> 32 chars, UPPERCASE</span>
        <span><strong>NIC</strong> 12 chars (right-aligned for old NIC)</span>
        <span><strong>From/To Period</strong> <?php echo $contrib_period;?></span>
        <span><strong>Amount</strong> 9 chars, cents, zero-padded — <?php echo $etf_rate;?>% of Basic Adjusted Salary (Basic − No Pay)</span>
        <span><strong>HU</strong> trailer line with totals</span>
    </div>
</div>

<!-- SAVE BATCH MODAL -->
<div class="mo" id="bm">
    <div class="mb">
        <div class="mh">
            <i class="fa-solid fa-floppy-disk" style="font-size:20px;"></i>
            <div>
                <h3><?php echo $existing_batch ? 'Update ETF Batch' : 'Save ETF Batch'; ?></h3>
                <div style="font-size:11px;opacity:.8;"><?php echo $active_period ? $month_names[$active_period['month']].' '.$active_period['year'] : ''; ?> · <?php echo count($employees); ?> employees</div>
            </div>
        </div>
        <div class="mbd">
            <?php if ($existing_batch): ?>
            <p style="background:#fef3c7;border:1px solid #fde68a;border-radius:7px;padding:9px 13px;color:#92400e;font-weight:600;"><i class="fa-solid fa-triangle-exclamation"></i> A batch already exists for this period. Saving will <strong>replace</strong> it.</p>
            <?php else: ?>
            <p style="font-size:13px;color:#374151;">Save the current ETF data as a batch for <strong><?php echo $active_period ? $month_names[$active_period['month']].' '.$active_period['year'] : 'this period'; ?></strong>.</p>
            <?php endif; ?>
            <div class="mrow">
                <div class="mf"><label>Employees</label><input type="text" value="<?php echo count($employees);?>" readonly style="background:#f8fafc;"></div>
                <div class="mf"><label>Total ETF (<?php echo $etf_rate;?>% of Basic Adjusted)</label><input type="text" value="<?php echo number_format($totals['etf_er'],2);?>" readonly style="background:#f8fafc;font-weight:700;color:#0f766e;"></div>
            </div>
            <div class="mf"><label>Notes (optional)</label><textarea id="bn" rows="2" placeholder="Any notes for this ETF batch…" style="resize:none;"></textarea></div>
        </div>
        <div class="mft">
            <button class="btn btn-cn" onclick="closeModal()">Cancel</button>
            <button class="btn btn-ok" id="sb" onclick="saveBatch()">
                <i class="fa-solid fa-floppy-disk"></i> <?php echo $existing_batch ? 'Update Batch' : 'Save Batch'; ?>
            </button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
const ETF_ENTRIES=<?php
$ea=[];
foreach($employees as $emp){
    $eid=(int)$emp['id'];$r=$rows[$eid];
    $ea[]=['employee_id'=>$eid,'epf_number'=>$emp['epf_number'],'nic'=>strtoupper(trim($emp['id_number']??'')),'full_name'=>$emp['employee_full_name'],'surname'=>$r['surname'],'initials'=>$r['initials'],'basic'=>$r['basic'],'no_pay'=>$r['no_pay'],'basic_adjusted'=>$r['basic_adjusted'],'etf_er'=>$r['etf_er'],'att_days'=>$r['att_days'],'source'=>$r['source']];
}
echo json_encode($ea,JSON_UNESCAPED_UNICODE);
?>;
const PID=<?php echo intval($sel_period_id);?>,TETF=<?php echo floatval($totals['etf_er']);?>,EC=<?php echo count($employees);?>;

function openModal(){document.getElementById('bm').classList.add('open');}
function closeModal(){document.getElementById('bm').classList.remove('open');}

function saveBatch(){
    const btn=document.getElementById('sb');
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    const fd=new FormData();
    fd.append('action','save_etf_batch');fd.append('period_id',PID);
    fd.append('total_etf',TETF);
    fd.append('notes',document.getElementById('bn').value);
    fd.append('entries',JSON.stringify(ETF_ENTRIES));
    fetch('etf_report.php',{method:'POST',body:fd})
        .then(r=>r.json()).then(d=>{
            closeModal();
            if(d.success){showToast('s','✓ '+d.msg);setTimeout(()=>location.reload(),1200);}
            else showToast('e','✗ '+(d.msg||'Save failed'));
        }).catch(()=>showToast('e','✗ Network error'))
        .finally(()=>{btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Batch';});
}

function showToast(t,m){const x=document.getElementById('toast');x.textContent=m;x.className='toast t-'+t;setTimeout(()=>x.classList.add('show'),10);setTimeout(()=>x.classList.remove('show'),3400);}

function lf(q){
    q=q.trim().toLowerCase();
    const rows=document.querySelectorAll('#etb tr');let v=0;
    rows.forEach(r=>{const s=r.getAttribute('data-s')||'';const show=!q||s.includes(q);r.style.display=show?'':'none';if(show)v++;});
    const n=document.getElementById('nr');if(n)n.style.display=v===0&&q?'block':'none';
}
document.getElementById('bm').addEventListener('click',function(e){if(e.target===this)closeModal();});
</script>

<?php include 'footer.php'; ?>
<?php
/**
 * EPF REPORT PAGE  (EPF only — ETF section removed)
 * Features:
 *  - Permanent employees with EPF numbers
 *  - Payroll period selector
 *  - EPF contribution amounts (Employee % + Employer %) — calculated on
 *    Basic Adjusted Salary (Basic Salary − No Pay), not raw Basic
 *  - Working Days = att_days from salary_sheet_entries
 *  - Save Batch / Update Batch button
 *  - Excel + TXT downloads
 *  - Link to epf_batches.php
 *
 * DB TABLES (auto-created on first load):
 *   epf_batches       — one row per payroll period
 *   epf_batch_entries — one row per employee per batch
 */
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'config.php';

// ── Auto-create tables ────────────────────────────────────────────────────────
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS epf_batches (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        payroll_period_id INT NOT NULL,
        batch_date        DATE NOT NULL,
        total_employees   INT           NOT NULL DEFAULT 0,
        total_basic       DECIMAL(14,2) NOT NULL DEFAULT 0,
        total_epf_emp     DECIMAL(14,2) NOT NULL DEFAULT 0,
        total_epf_er      DECIMAL(14,2) NOT NULL DEFAULT 0,
        total_epf         DECIMAL(14,2) NOT NULL DEFAULT 0,
        notes             TEXT,
        created_by        INT,
        created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_period (payroll_period_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS epf_batch_entries (
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
        epf_emp        DECIMAL(12,2) DEFAULT 0,
        epf_er         DECIMAL(12,2) DEFAULT 0,
        total_epf      DECIMAL(12,2) DEFAULT 0,
        att_days       INT           DEFAULT 0,
        source         VARCHAR(10)   DEFAULT 'live',
        FOREIGN KEY (batch_id) REFERENCES epf_batches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
// Safety-net migration in case epf_batch_entries already existed on this
// install without the No Pay / Basic Adjusted Salary columns.
foreach ([
    "ALTER TABLE epf_batch_entries ADD COLUMN IF NOT EXISTS no_pay DECIMAL(12,2) DEFAULT 0",
    "ALTER TABLE epf_batch_entries ADD COLUMN IF NOT EXISTS basic_adjusted DECIMAL(12,2) DEFAULT 0",
] as $mig) @mysqli_query($conn, $mig);

// ── Helpers ───────────────────────────────────────────────────────────────────
function deriveEpfNameParts(string $fullName): array {
    $words = preg_split('/\s+/', trim(strtoupper($fullName)));
    $words = array_values(array_filter($words));
    if (!$words) return ['', ''];
    if (count($words) === 1) return [$words[0], ''];
    $surname  = array_pop($words);
    $initials = implode(' ', array_map(fn($w) => $w[0], $words));
    return [$surname, $initials];
}

function formatNic(string $nic): string {
    $nic = strtoupper(trim($nic));
    if (strlen($nic) === 12 && ctype_digit($nic)) return str_repeat(' ', 8) . $nic;
    return str_pad($nic, 20);
}

function buildTxtLine(
    string $nic, string $surname, string $initials, int $memberNo,
    float $totalContrib, float $epfEr, float $epfEmp, float $totalEarnings,
    string $memberStatus, string $zone, string $employerNo, string $period,
    int $dataSubmission, int $daysWorked, int $grade
): string {
    $line =
        formatNic($nic) .
        str_pad(substr(strtoupper($surname), 0, 36), 36) .
        str_pad('    ' . strtoupper($initials), 24) .
        str_pad((string)$memberNo, 6, ' ', STR_PAD_LEFT) .
        '   ' . str_pad(number_format($totalContrib, 2, '.', ''), 7, ' ', STR_PAD_LEFT) .
        '   ' . str_pad(number_format($epfEr, 2, '.', ''), 7, ' ', STR_PAD_LEFT) .
        '   ' . str_pad(number_format($epfEmp, 2, '.', ''), 7, ' ', STR_PAD_LEFT) .
        '    ' . str_pad(number_format($totalEarnings, 2, '.', ''), 8, ' ', STR_PAD_LEFT) .
        strtoupper($memberStatus[0] ?? 'E') . strtoupper($zone[0] ?? 'U') .
        ' ' . str_pad(substr($employerNo, 0, 5), 5) .
        str_pad(substr($period, 0, 6), 6) .
        ' ' . (string)$dataSubmission .
        '   ' . str_pad((string)$daysWorked, 2, ' ', STR_PAD_LEFT) .
        ' ' . str_pad((string)$grade, 2);
    return $line . "\r\n";
}

// ── EPF Settings ──────────────────────────────────────────────────────────────
$epf_settings      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM epf_etf_settings LIMIT 1"));
$epf_employee_rate = $epf_settings ? floatval($epf_settings['epf_employee']) : 8;
$epf_employer_rate = $epf_settings ? floatval($epf_settings['epf_employer']) : 12;
$employer_number   = $epf_settings['employer_number'] ?? '';

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
$search_q       = !empty($_GET['q'])              ? mysqli_real_escape_string($conn, trim($_GET['q'])) : '';

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
           d.designation_name,
           sc.category_name, sc.category_code
    FROM employees e
    LEFT JOIN companies c        ON e.company_id = c.id
    LEFT JOIN branches b         ON e.branch_id = b.id
    LEFT JOIN designations d     ON e.designation_id = d.id
    LEFT JOIN staff_categories sc ON e.staff_category_id = sc.id
    WHERE $where
    ORDER BY c.company_code, CAST(e.epf_number AS UNSIGNED), e.employee_full_name
");
$employees = [];
while ($row = mysqli_fetch_assoc($emp_res)) $employees[] = $row;

// ── Sheet data (basic + no_pay_amount + att_days from salary_sheet_entries) ──
// NOTE: epf_emp / epf_er are now always DERIVED here from Basic Adjusted
// Salary (see below), never read from saved epf_emp/epf_er snapshot columns
// — a saved figure could have been computed against raw Basic before No Pay
// was known/applied, which would silently misstate the actual EPF liability.
$sheet_data = [];
if ($sel_period_id && !empty($employees)) {
    $ids_str = implode(',', array_map(fn($e) => (int)$e['id'], $employees));
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

// ── Per-employee EPF rows ─────────────────────────────────────────────────────
// Basic Adjusted Salary = Basic Salary − No Pay. Both EPF Employee and EPF
// Employer contributions are calculated on this ADJUSTED figure, not raw
// Basic, so No Pay days are correctly excluded from the contribution base.
$rows   = [];
$totals = ['basic'=>0,'no_pay'=>0,'basic_adjusted'=>0,'epf_emp'=>0,'epf_er'=>0,'total_epf'=>0];
foreach ($employees as $emp) {
    $eid = (int)$emp['id'];
    if (isset($sheet_data[$eid])) {
        $basic    = floatval($sheet_data[$eid]['basic']);
        $no_pay   = floatval($sheet_data[$eid]['no_pay_amount'] ?? 0);
        $att_days = intval($sheet_data[$eid]['att_days'] ?? 0);
        $source   = 'sheet';
    } else {
        $basic    = floatval($emp['basic_salary'] ?? 0);
        $no_pay   = 0.0;
        $att_days = 0;
        $source   = 'live';
    }
    $basic_adjusted = round($basic - $no_pay, 2);
    $epf_emp        = round($basic_adjusted * $epf_employee_rate / 100, 2);
    $epf_er         = round($basic_adjusted * $epf_employer_rate / 100, 2);
    $total_epf      = $epf_emp + $epf_er;
    [$surname, $initials] = deriveEpfNameParts($emp['employee_full_name']);
    $rows[$eid]              = compact('basic','no_pay','basic_adjusted','epf_emp','epf_er','total_epf','source','att_days','surname','initials');
    $totals['basic']         += $basic;
    $totals['no_pay']        += $no_pay;
    $totals['basic_adjusted']+= $basic_adjusted;
    $totals['epf_emp']       += $epf_emp;
    $totals['epf_er']        += $epf_er;
    $totals['total_epf']     += $total_epf;
}

// ── Existing batch for this period ────────────────────────────────────────────
$existing_batch = null;
if ($sel_period_id) {
    $bchk = mysqli_query($conn, "SELECT * FROM epf_batches WHERE payroll_period_id = $sel_period_id LIMIT 1");
    if ($bchk && mysqli_num_rows($bchk) > 0) $existing_batch = mysqli_fetch_assoc($bchk);
}

// ── POST: Save Batch ──────────────────────────────────────────────────────────
// ── POST: Save Batch ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_batch') {
    ob_clean();
    header('Content-Type: application/json');

    $pid = intval($_POST['period_id'] ?? 0);
    if (!$pid) { echo json_encode(['success'=>false,'msg'=>'Invalid period']); exit; }

    // Build batch_label from period  e.g. "2026-05"
    $period_row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT year, month FROM payroll_periods WHERE id = $pid LIMIT 1"));
    $batch_label = $period_row
        ? sprintf('%04d-%02d', $period_row['year'], $period_row['month'])
        : date('Y-m');

    // Count employees being submitted
    $entries      = json_decode($_POST['entries'] ?? '[]', true);
    $total_members = count($entries);
    $t_ee   = floatval($_POST['total_epf_emp'] ?? 0);
    $t_er   = floatval($_POST['total_epf_er']  ?? 0);
    $t_epf  = floatval($_POST['total_epf']     ?? 0);

    // Remove existing batch for this period (replace)
    $old = mysqli_query($conn,
        "SELECT id FROM epf_batches WHERE payroll_period_id = $pid LIMIT 1");
    if ($old && mysqli_num_rows($old) > 0) {
        $o = mysqli_fetch_assoc($old);
        mysqli_query($conn,
            "DELETE FROM epf_batches WHERE id = " . intval($o['id']));
    }

    // INSERT using the real column names
    $bl = mysqli_real_escape_string($conn, $batch_label);
    $ok = mysqli_query($conn, "
        INSERT INTO epf_batches
            (payroll_period_id, batch_label, submission_no,
             total_members, total_epf_emp, total_epf_er, total_epf,
             status, created_at)
        VALUES ($pid, '$bl', 1,
                $total_members, $t_ee, $t_er, $t_epf,
                'Pending', NOW())
    ");

    if (!$ok) {
        echo json_encode(['success'=>false,
            'msg'=>'DB error (batch): '.mysqli_error($conn)]);
        exit;
    }

    $bid = mysqli_insert_id($conn);

    // INSERT entries
    $failed = 0;
    foreach ($entries as $e) {
        $eid_e  = intval($e['employee_id']);
        $epf_no = mysqli_real_escape_string($conn, $e['epf_number'] ?? '');
        $nic_e  = mysqli_real_escape_string($conn, $e['nic']        ?? '');
        $fname  = mysqli_real_escape_string($conn, $e['full_name']  ?? '');
        $sname  = mysqli_real_escape_string($conn, $e['surname']    ?? '');
        $init   = mysqli_real_escape_string($conn, $e['initials']   ?? '');
        $bas_e  = floatval($e['basic']     ?? 0);
        $np_e   = floatval($e['no_pay']    ?? 0);
        $badj_e = floatval($e['basic_adjusted'] ?? ($bas_e - $np_e));
        $ee_e   = floatval($e['epf_emp']   ?? 0);
        $er_e   = floatval($e['epf_er']    ?? 0);
        $tot_e  = floatval($e['total_epf'] ?? 0);
        $att_e  = intval($e['att_days']    ?? 0);
        $src_e  = mysqli_real_escape_string($conn, $e['source']     ?? 'live');

        $r = mysqli_query($conn, "
            INSERT INTO epf_batch_entries
                (batch_id, employee_id, epf_number, nic, full_name,
                 surname, initials, basic, no_pay, basic_adjusted,
                 epf_emp, epf_er, total_epf, att_days, source)
            VALUES ($bid, $eid_e, '$epf_no', '$nic_e', '$fname',
                    '$sname', '$init', $bas_e, $np_e, $badj_e,
                    $ee_e, $er_e, $tot_e, $att_e, '$src_e')
        ");
        if (!$r) $failed++;
    }

    if ($failed > 0) {
        echo json_encode(['success'=>false,
            'msg'=>"Batch header saved but $failed entries failed: "
                  .mysqli_error($conn)]);
        exit;
    }

    echo json_encode(['success'=>true, 'batch_id'=>$bid,
                      'msg'=>'Batch saved successfully!']);
    exit;
}

// ── Minimal dependency-free XLSX writer (ZipArchive only — no python3/openpyxl
//    needed, so this can never fail due to a missing external interpreter or
//    package on the server; this is the same approach already proven working
//    in salary_excel_generate.php) ──────────────────────────────────────────
class EpfXlsxWriter {
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
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF0C4A6E"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE0F2FE"/><bgColor indexed="64"/></patternFill></fill>'
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
        $tmp = tempnam(sys_get_temp_dir(),'epfxlsx'); @unlink($tmp);
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
            . '<sheets><sheet name="EPF Report" sheetId="1" r:id="rId1"/></sheets></workbook>';
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
function epfColRef($i){ $i++; $s=''; while($i>0){ $m=($i-1)%26; $s=chr(65+$m).$s; $i=intdiv($i-1,26);} return $s; }

// ── Excel download ─────────────────────────────────────────────────────────────
if (isset($_GET['download']) && $_GET['download'] === 'excel') {
    ob_clean();
    $lbl = $active_period ? $month_names[$active_period['month']].'_'.$active_period['year'] : 'export';

    $COLS = [
        ['label'=>'#',                     'w'=>4,  'type'=>'n'],
        ['label'=>'NIC',                   'w'=>14, 'type'=>'s'],
        ['label'=>'Surname',               'w'=>22, 'type'=>'s'],
        ['label'=>'Initials',              'w'=>10, 'type'=>'s'],
        ['label'=>'EPF No',                'w'=>10, 'type'=>'s'],
        ['label'=>'Employee Name',         'w'=>28, 'type'=>'s'],
        ['label'=>'Code',                  'w'=>10, 'type'=>'s'],
        ['label'=>'Company',               'w'=>22, 'type'=>'s'],
        ['label'=>'Designation',           'w'=>20, 'type'=>'s'],
        ['label'=>'Joined',                'w'=>12, 'type'=>'s'],
        ['label'=>'Basic',                 'w'=>13, 'type'=>'n'],
        ['label'=>'No Pay',                'w'=>12, 'type'=>'n'],
        ['label'=>'Basic Adjusted Salary', 'w'=>16, 'type'=>'n'],
        ['label'=>"EPF Ee {$epf_employee_rate}%", 'w'=>13, 'type'=>'n'],
        ['label'=>"EPF Er {$epf_employer_rate}%", 'w'=>13, 'type'=>'n'],
        ['label'=>'Total EPF',             'w'=>14, 'type'=>'n'],
    ];
    $ncols = count($COLS);

    $xl = new EpfXlsxWriter();
    $xl->setWidths(array_column($COLS,'w'));

    $xl->addRow([[ 'EPF CONTRIBUTION REPORT', 's', EpfXlsxWriter::S_TITLE ]]);
    $xl->merge('A1:'.epfColRef($ncols-1).'1');
    $periodLbl = $active_period ? $month_names[$active_period['month']].' '.$active_period['year'] : 'N/A';
    $xl->addRow([[ "Period: $periodLbl | EPF Ee: {$epf_employee_rate}% | EPF Er: {$epf_employer_rate}% (on Basic Adjusted Salary) | Employer No: $employer_number", 's', EpfXlsxWriter::S_SUB ]]);
    $xl->merge('A2:'.epfColRef($ncols-1).'2');

    $hdr = [];
    foreach ($COLS as $c) $hdr[] = [$c['label'], 's', EpfXlsxWriter::S_HEADER];
    $xl->addRow($hdr);

    $n = 0;
    foreach ($employees as $emp) {
        $eid = (int)$emp['id']; $r = $rows[$eid]; $n++;
        $xl->addRow([
            [$n, 'n', EpfXlsxWriter::S_DEFAULT],
            [strtoupper(trim($emp['id_number']??'')), 's', EpfXlsxWriter::S_DEFAULT],
            [$r['surname'], 's', EpfXlsxWriter::S_DEFAULT],
            [$r['initials'], 's', EpfXlsxWriter::S_DEFAULT],
            [$emp['epf_number'], 's', EpfXlsxWriter::S_DEFAULT],
            [$emp['employee_full_name'], 's', EpfXlsxWriter::S_DEFAULT],
            [$emp['employee_id'], 's', EpfXlsxWriter::S_DEFAULT],
            [trim(($emp['company_code']??'').' - '.($emp['company_name']??''), ' -'), 's', EpfXlsxWriter::S_DEFAULT],
            [$emp['designation_name'] ?? '', 's', EpfXlsxWriter::S_DEFAULT],
            [$emp['date_of_join'] ? date('d/m/Y', strtotime($emp['date_of_join'])) : '', 's', EpfXlsxWriter::S_DEFAULT],
            [$r['basic'], 'n', EpfXlsxWriter::S_NUM],
            [$r['no_pay'], 'n', EpfXlsxWriter::S_NUM],
            [$r['basic_adjusted'], 'n', EpfXlsxWriter::S_NUM],
            [$r['epf_emp'], 'n', EpfXlsxWriter::S_NUM],
            [$r['epf_er'], 'n', EpfXlsxWriter::S_NUM],
            [$r['total_epf'], 'n', EpfXlsxWriter::S_NUM],
        ]);
    }

    $totRow = [];
    foreach ($COLS as $idx0 => $c) {
        if ($idx0 === 0) { $totRow[] = ["TOTALS ($n employees)", 's', EpfXlsxWriter::S_TEXT_BOLD]; continue; }
        if ($idx0 < 10) { $totRow[] = ['', 's', EpfXlsxWriter::S_TEXT_BOLD]; continue; }
        $key = ['basic','no_pay','basic_adjusted','epf_emp','epf_er','total_epf'][$idx0-10];
        $totRow[] = [round($totals[$key],2), 'n', EpfXlsxWriter::S_NUM_BOLD];
    }
    $xl->addRow($totRow);
    $xl->merge('A'.($n+4).':J'.($n+4));

    $xl->output('EPF_Report_'.$lbl.'.xlsx');
}

// ── TXT download ───────────────────────────────────────────────────────────────
if (isset($_GET['download']) && $_GET['download'] === 'txt') {
    ob_clean();
    $lbl = $active_period ? $month_names[$active_period['month']].'_'.$active_period['year'] : 'export';
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="EPF_Macro_'.$lbl.'.txt"');
    foreach ($employees as $emp) {
        $eid = (int)$emp['id']; $r = $rows[$eid];
        // "Total Earnings" field reported here is the Basic Adjusted Salary
        // (Basic − No Pay) — the same figure the Ee/Er contributions were
        // calculated on, so the submission is internally consistent.
        echo buildTxtLine(
            strtoupper(trim($emp['id_number']??'')),
            $r['surname'],$r['initials'],(int)$emp['epf_number'],
            $r['epf_emp']+$r['epf_er'],$r['epf_er'],$r['epf_emp'],$r['basic_adjusted'],
            'E','U',$employer_number,$contrib_period,1,
            $r['att_days']>0?$r['att_days']:0,12
        );
    }
    exit;
}

// ── Filter dropdowns ──────────────────────────────────────────────────────────
$companies_res = mysqli_query($conn, "SELECT id, company_code, company_name FROM companies WHERE active=1 ORDER BY company_name");
$branches_res  = $filter_company
    ? mysqli_query($conn, "SELECT id, branch_code, branch_name FROM branches WHERE company_id=$filter_company AND active=1 ORDER BY branch_name")
    : null;

include 'header.php';
?>
<style>
:root{--dk:#0c4a6e;--md:#0369a1;--lt:#e0f2fe;--gn:#166534;--gl:#dcfce7;--am:#92400e;--al:#fef3c7;--bd:#e2e8f0;--pu:#7c3aed;--pl:#f3e8ff;}
.epf-hdr{background:linear-gradient(135deg,var(--dk),var(--md));color:#fff;padding:22px 28px 18px;border-radius:0 0 14px 14px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;}
.epf-hdr-title{font-size:21px;font-weight:800;display:flex;align-items:center;gap:10px;}
.epf-hdr-sub{font-size:12px;color:#7dd3fc;margin-top:3px;}
.epf-acts{display:flex;gap:9px;align-items:center;flex-wrap:wrap;}
.pp-pill{display:flex;align-items:center;gap:9px;background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.22);border-radius:9px;padding:9px 14px;}
.pp-pill label{font-size:10px;color:#7dd3fc;font-weight:700;text-transform:uppercase;letter-spacing:.4px;display:block;}
.pp-pill select{background:transparent;border:none;outline:none;color:#fff;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit;}
.pp-pill select option{background:#0c4a6e;}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;border:none;text-decoration:none;font-family:inherit;white-space:nowrap;transition:all .18s;}
.btn-xl{background:#166534;color:#fff;}.btn-xl:hover{background:#14532d;}
.btn-tx{background:#78350f;color:#fff;}.btn-tx:hover{background:#6b3009;}
.btn-pr{background:rgba(255,255,255,.13);color:#fff;border:1px solid rgba(255,255,255,.25);}.btn-pr:hover{background:rgba(255,255,255,.22);}
.btn-bt{background:var(--pu);color:#fff;}.btn-bt:hover{background:#6d28d9;}
.btn-bl{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);}.btn-bl:hover{background:rgba(255,255,255,.25);}
.stats{display:flex;gap:11px;flex-wrap:wrap;margin-bottom:16px;}
.sc{background:#fff;border:1px solid var(--bd);border-radius:11px;padding:13px 18px;flex:1;min-width:145px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.sc-lb{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:4px;}
.sc-vl{font-size:17px;font-weight:800;color:var(--dk);font-family:'Courier New',monospace;}
.sc-sb{font-size:11px;color:#64748b;margin-top:2px;}
.sc.gn .sc-vl{color:var(--gn);}
.sc.pu .sc-vl{color:var(--pu);font-size:15px;}
.sc.rd .sc-vl{color:#dc2626;font-size:15px;}
.sc.ix .sc-vl{color:#3730a3;font-size:15px;}
.saved-banner{background:var(--gl);border:1.5px solid #86efac;border-radius:9px;padding:11px 16px;margin-bottom:14px;display:flex;align-items:center;gap:10px;font-size:13px;color:var(--gn);font-weight:700;}
.saved-banner a{color:var(--md);margin-left:10px;font-weight:700;}
.fbar{background:#fff;border:1px solid var(--bd);border-radius:11px;padding:13px 18px;margin-bottom:16px;display:flex;gap:11px;flex-wrap:wrap;align-items:flex-end;}
.fg{display:flex;flex-direction:column;gap:4px;flex:1;min-width:155px;}
.fg label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#64748b;}
.fi{padding:8px 11px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:12px;font-family:inherit;background:#fff;color:#111;outline:none;}
.fi:focus{border-color:var(--md);box-shadow:0 0 0 3px rgba(3,105,161,.1);}
.btn-fa{padding:8px 16px;border-radius:7px;border:none;background:var(--dk);color:#fff;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;}
.btn-fa:hover{background:var(--md);}
.btn-cl{padding:8px 12px;border-radius:7px;border:1px solid var(--bd);background:#f8fafc;color:#374151;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:5px;}
.lgnd{display:flex;gap:14px;margin-bottom:12px;flex-wrap:wrap;align-items:center;background:#fff;border:1px solid var(--bd);border-radius:7px;padding:9px 14px;font-size:12px;}
.ld{width:10px;height:10px;border-radius:3px;display:inline-block;margin-right:4px;}
.ld-s{background:#dcfce7;border:1.5px solid #86efac;}.ld-l{background:#fef3c7;border:1.5px solid #fde68a;}
.tbl-card{background:#fff;border:1px solid var(--bd);border-radius:13px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.05);}
.tbl-hdr{display:flex;align-items:center;justify-content:space-between;padding:13px 18px;background:var(--lt);border-bottom:1px solid #bae6fd;flex-wrap:wrap;gap:9px;}
.tbl-ttl{font-size:14px;font-weight:800;color:var(--dk);display:flex;align-items:center;gap:7px;}
.sw{position:relative;}.sw i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:12px;}
.si{padding:8px 11px 8px 32px;border:1.5px solid var(--bd);border-radius:7px;font-size:12px;font-family:inherit;outline:none;width:200px;}
.si:focus{border-color:var(--md);}
.etbl{width:100%;border-collapse:collapse;font-size:12px;}
.etbl thead tr{background:#1e293b;}
.etbl thead th{padding:9px 11px;color:#94a3b8;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;text-align:right;border-right:1px solid #2d3748;white-space:nowrap;}
.etbl thead th.tl{text-align:left;}.etbl thead th.tc{text-align:center;}
.etbl thead .t-nic{background:#1e3a5f!important;color:#93c5fd;}
.etbl thead .t-nm{background:#1e293b!important;}
.etbl thead .t-earn{background:#042f2e;color:#5eead4;}
.etbl thead .t-np{background:#7f1d1d!important;color:#fca5a5;}
.etbl thead .t-badj{background:#3730a3!important;color:#c7d2fe;}
.etbl thead .t-epf{background:#0c4a6e;color:#7dd3fc;}
.etbl thead .t-tot{background:#4c1d95;color:#c4b5fd;}
.etbl thead .t-att{background:#374151;color:#d1d5db;}
.etbl tbody tr{border-bottom:1px solid #f1f5f9;transition:background .1s;}
.etbl tbody tr:nth-child(even){background:#f8fafc;}
.etbl tbody tr:hover{background:#eff6ff!important;}
.etbl td{padding:9px 11px;vertical-align:middle;text-align:right;}
.etbl td.tl{text-align:left;}.etbl td.tc{text-align:center;}
.tr-tot{background:#1e293b!important;}
.tr-tot td{color:#e2e8f0;font-weight:700;padding:11px;font-size:12px;font-family:'Courier New',monospace;border-right:1px solid #2d3748;}
.nic-c{font-family:'Courier New',monospace;font-size:11px;font-weight:700;color:#1e40af;background:#dbeafe;padding:2px 6px;border-radius:4px;}
.sn{font-weight:800;font-size:13px;color:var(--dk);font-family:'Courier New',monospace;}
.et{font-family:'Courier New',monospace;font-size:11px;font-weight:700;color:var(--md);background:var(--lt);padding:2px 6px;border-radius:4px;}
.en{font-weight:700;font-size:12px;color:#111;}
.ei{font-size:11px;color:#64748b;margin-top:1px;}
.ec{font-family:'Courier New',monospace;font-size:10px;color:#94a3b8;}
.m-earn{font-family:'Courier New',monospace;font-weight:600;color:var(--dk);}
.m-np{font-family:'Courier New',monospace;font-weight:600;color:#b91c1c;}
.m-badj{font-family:'Courier New',monospace;font-weight:700;color:#3730a3;background:#eef2ff;padding:2px 7px;border-radius:4px;}
.m-ee{font-family:'Courier New',monospace;font-weight:600;color:var(--md);}
.m-er{font-family:'Courier New',monospace;font-weight:600;color:#0e7490;}
.m-tot{font-family:'Courier New',monospace;font-weight:800;color:var(--pu);font-size:13px;}
.m-z{color:#d1d5db;font-size:11px;}
.bs{display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border-radius:20px;font-size:10px;font-weight:700;}
.bs-s{background:var(--gl);color:var(--gn);border:1px solid #86efac;}
.bs-l{background:var(--al);color:var(--am);border:1px solid #fde68a;}
.ac{display:inline-block;font-family:monospace;font-size:11px;font-weight:700;padding:2px 7px;border-radius:4px;background:#f1f5f9;color:#475569;}
.ac.ok{background:var(--gl);color:var(--gn);}
.ac.zz{background:#f1f5f9;color:#94a3b8;}
/* Modal */
.mo{position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:9999;display:none;align-items:center;justify-content:center;}
.mo.open{display:flex;}
.mb{background:#fff;border-radius:14px;width:480px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,.28);overflow:hidden;}
.mh{background:linear-gradient(135deg,#4c1d95,#7c3aed);color:#fff;padding:18px 22px;display:flex;align-items:center;gap:11px;}
.mh h3{margin:0;font-size:15px;font-weight:800;}
.mbd{padding:22px;}
.mbd p{font-size:13px;color:#374151;margin:0 0 14px;line-height:1.6;}
.mrow{display:flex;gap:9px;margin-bottom:12px;}
.mf{flex:1;}
.mf label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#64748b;display:block;margin-bottom:4px;}
.mf input,.mf textarea{width:100%;padding:8px 11px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:12px;font-family:inherit;outline:none;}
.mf input:focus,.mf textarea:focus{border-color:#7c3aed;}
.mft{padding:14px 22px;background:#f8fafc;border-top:1px solid #e2e8f0;display:flex;gap:9px;justify-content:flex-end;}
.btn-cn{padding:9px 18px;border-radius:7px;border:1px solid #e2e8f0;background:#fff;color:#374151;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;}
.btn-ok{padding:9px 22px;border-radius:7px;border:none;background:#7c3aed;color:#fff;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:7px;}
.btn-ok:hover{background:#6d28d9;}.btn-ok:disabled{background:#a78bfa;cursor:not-allowed;}
/* Toast */
.toast{position:fixed;bottom:26px;right:26px;background:#1e293b;color:#fff;padding:13px 20px;border-radius:9px;font-size:13px;font-weight:700;z-index:99999;transform:translateY(70px);opacity:0;transition:all .32s cubic-bezier(.34,1.56,.64,1);display:flex;align-items:center;gap:9px;}
.toast.show{transform:translateY(0);opacity:1;}
.toast.t-s{background:#166534;}.toast.t-e{background:#991b1b;}
@media print{.epf-acts,.fbar{display:none!important;}@page{size:A3 landscape;margin:12mm;}}
</style>

<!-- PAGE HEADER -->
<div class="epf-hdr">
    <div>
        <div class="epf-hdr-title"><i class="fa-solid fa-shield-halved"></i> EPF Contribution Report</div>
        <div class="epf-hdr-sub">
            Permanent Employees · EPF Only · on Basic Adjusted Salary
            · Ee: <?php echo $epf_employee_rate; ?>% · Er: <?php echo $epf_employer_rate; ?>%
            <?php if ($employer_number): ?> · Employer No: <strong style="color:#fff;"><?php echo htmlspecialchars($employer_number); ?></strong><?php endif; ?>
        </div>
    </div>
    <div class="epf-acts">
        <form method="GET" id="pf" style="display:contents;">
            <?php if (!empty($all_periods)): ?>
            <div class="pp-pill">
                <i class="fa-solid fa-calendar-check" style="color:#7dd3fc;"></i>
                <div>
                    <label>Payroll Period</label>
                    <select name="period_id" onchange="document.getElementById('pf').submit()">
                        <?php foreach ($all_periods as $pp): ?>
                        <option value="<?php echo $pp['id']; ?>" <?php echo $sel_period_id==$pp['id']?'selected':''; ?>>
                            <?php echo $month_names[$pp['month']].' '.$pp['year'].($pp['status']!=='Open'?' ('.$pp['status'].')':''); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php if ($filter_company): ?><input type="hidden" name="filter_company" value="<?php echo $filter_company; ?>"><?php endif; ?>
            <?php if ($filter_branch):  ?><input type="hidden" name="filter_branch"  value="<?php echo $filter_branch; ?>"><?php endif; ?>
            <?php if ($search_q):       ?><input type="hidden" name="q"              value="<?php echo htmlspecialchars($search_q); ?>"><?php endif; ?>
            <?php endif; ?>
        </form>
        <a href="epf_batches.php" class="btn btn-bl"><i class="fa-solid fa-layer-group"></i> Batches</a>
        <a href="?period_id=<?php echo $sel_period_id.'&download=excel'.($filter_company?'&filter_company='.$filter_company:'').($filter_branch?'&filter_branch='.$filter_branch:'').($search_q?'&q='.urlencode($search_q):''); ?>" class="btn btn-xl"><i class="fa-solid fa-file-excel"></i> Excel</a>
        <a href="?period_id=<?php echo $sel_period_id.'&download=txt'.($filter_company?'&filter_company='.$filter_company:'').($filter_branch?'&filter_branch='.$filter_branch:'').($search_q?'&q='.urlencode($search_q):''); ?>" class="btn btn-tx"><i class="fa-solid fa-file-lines"></i> TXT</a>
        <?php if (!empty($employees)): ?>
        <button class="btn btn-bt" onclick="openModal()">
            <i class="fa-solid fa-<?php echo $existing_batch?'rotate':'floppy-disk'; ?>"></i>
            <?php echo $existing_batch?'Update Batch':'Save Batch'; ?>
        </button>
        <?php endif; ?>
        <button class="btn btn-pr" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </div>
</div>

<!-- SAVED BANNER -->
<?php if ($existing_batch): ?>
<div class="saved-banner">
    <i class="fa-solid fa-circle-check" style="font-size:19px;"></i>
    Batch saved for <?php echo $active_period?$month_names[$active_period['month']].' '.$active_period['year']:''; ?>
   · <?php echo date('d M Y',strtotime($existing_batch['created_at'])); ?>
· <?php echo $existing_batch['total_members']; ?> employees
· Total EPF: <strong><?php echo number_format($existing_batch['total_epf'],2); ?></strong>
    <a href="epf_batches.php"><i class="fa-solid fa-arrow-right"></i> View Batches</a>
</div>
<?php endif; ?>

<!-- STATS (EPF only — no ETF) -->
<div class="stats">
    <div class="sc">
        <div class="sc-lb"><i class="fa-solid fa-users"></i> Employees</div>
        <div class="sc-vl"><?php echo count($employees); ?></div>
        <div class="sc-sb">Permanent w/ EPF</div>
    </div>
    <div class="sc">
        <div class="sc-lb"><i class="fa-solid fa-money-bill-wave"></i> Total Basic</div>
        <div class="sc-vl"><?php echo number_format($totals['basic'],2); ?></div>
        <div class="sc-sb">This period</div>
    </div>
    <div class="sc rd">
        <div class="sc-lb"><i class="fa-solid fa-calendar-xmark"></i> Total No Pay</div>
        <div class="sc-vl"><?php echo number_format($totals['no_pay'],2); ?></div>
        <div class="sc-sb">Deducted before EPF calc</div>
    </div>
    <div class="sc ix">
        <div class="sc-lb"><i class="fa-solid fa-scale-balanced"></i> Basic Adjusted Salary</div>
        <div class="sc-vl"><?php echo number_format($totals['basic_adjusted'],2); ?></div>
        <div class="sc-sb">Basic − No Pay · EPF base</div>
    </div>
    <div class="sc gn">
        <div class="sc-lb"><i class="fa-solid fa-circle-arrow-down"></i> EPF Employee (<?php echo $epf_employee_rate; ?>%)</div>
        <div class="sc-vl"><?php echo number_format($totals['epf_emp'],2); ?></div>
        <div class="sc-sb">Employee contribution</div>
    </div>
    <div class="sc">
        <div class="sc-lb"><i class="fa-solid fa-circle-arrow-up"></i> EPF Employer (<?php echo $epf_employer_rate; ?>%)</div>
        <div class="sc-vl"><?php echo number_format($totals['epf_er'],2); ?></div>
        <div class="sc-sb">Employer contribution</div>
    </div>
    <div class="sc pu">
        <div class="sc-lb"><i class="fa-solid fa-sigma"></i> Total EPF Fund</div>
        <div class="sc-vl"><?php echo number_format($totals['total_epf'],2); ?></div>
        <div class="sc-sb">Employee + Employer</div>
    </div>
</div>

<!-- FILTER -->
<form method="GET" action="">
    <?php if ($sel_period_id): ?><input type="hidden" name="period_id" value="<?php echo $sel_period_id; ?>"><?php endif; ?>
    <div class="fbar">
        <div class="fg">
            <label>Company</label>
            <select name="filter_company" class="fi" onchange="this.form.submit()">
                <option value="">All Companies</option>
                <?php if ($companies_res){mysqli_data_seek($companies_res,0);while($c=mysqli_fetch_assoc($companies_res)):?>
                <option value="<?php echo $c['id'];?>" <?php echo $filter_company==$c['id']?'selected':'';?>><?php echo htmlspecialchars($c['company_code'].' — '.$c['company_name']);?></option>
                <?php endwhile;}?>
            </select>
        </div>
        <?php if ($filter_company&&$branches_res):?>
        <div class="fg">
            <label>Branch</label>
            <select name="filter_branch" class="fi" onchange="this.form.submit()">
                <option value="">All Branches</option>
                <?php while($br=mysqli_fetch_assoc($branches_res)):?>
                <option value="<?php echo $br['id'];?>" <?php echo $filter_branch==$br['id']?'selected':'';?>><?php echo htmlspecialchars($br['branch_code'].' — '.$br['branch_name']);?></option>
                <?php endwhile;?>
            </select>
        </div>
        <?php endif;?>
        <div class="fg">
            <label>Search</label>
            <input type="text" name="q" class="fi" placeholder="EPF No., Name, NIC…" value="<?php echo htmlspecialchars($search_q);?>">
        </div>
        <button type="submit" class="btn-fa"><i class="fa-solid fa-filter"></i> Filter</button>
        <?php if ($filter_company||$filter_branch||$search_q):?>
        <a href="epf_report.php<?php echo $sel_period_id?'?period_id='.$sel_period_id:'';?>" class="btn-cl"><i class="fa-solid fa-xmark"></i> Clear</a>
        <?php endif;?>
    </div>
</form>

<!-- LEGEND -->
<div class="lgnd">
    <strong style="color:#374151;font-size:12px;">Data Source:</strong>
    <span><span class="ld ld-s"></span><strong style="color:#166534;">Salary Sheet</strong></span>
    <span><span class="ld ld-l"></span><strong style="color:#92400e;">Live (Calculated)</strong></span>
    <?php if ($active_period):?>
    <span style="margin-left:auto;font-size:11px;color:#64748b;">
        <i class="fa-solid fa-calendar"></i> <?php echo $month_names[$active_period['month']].' '.$active_period['year'];?> · <?php echo $contrib_period;?> · <?php echo count($employees);?> employees
    </span>
    <?php endif;?>
</div>

<!-- TABLE -->
<div class="tbl-card">
    <div class="tbl-hdr">
        <div class="tbl-ttl"><i class="fa-solid fa-table"></i> EPF Contribution Table <span style="font-size:12px;font-weight:400;color:#64748b;"><?php echo count($employees);?> permanent employees</span></div>
        <div class="sw"><i class="fa-solid fa-magnifying-glass"></i><input type="text" class="si" id="ls" placeholder="Search…" oninput="lf(this.value)"></div>
    </div>
    <div style="overflow-x:auto;">
    <?php if(empty($employees)):?>
    <div style="text-align:center;padding:55px;color:#94a3b8;"><i class="fa-solid fa-shield-halved" style="font-size:38px;margin-bottom:11px;display:block;opacity:.25;"></i><p style="font-size:13px;">No permanent employees with EPF numbers found.</p></div>
    <?php else:?>
    <table class="etbl" id="et">
        <thead><tr>
            <th class="tl t-nm" style="width:28px;">#</th>
            <th class="tc t-nic">NIC Number</th>
            <th class="tl t-nic">Surname</th>
            <th class="tc t-nic">Initials</th>
            <th class="tc t-nm">EPF No.</th>
            <th class="tl t-nm">Employee Name</th>
            <th class="tl t-nm">Company / Branch</th>
            <th class="tl t-nm">Designation</th>
            <th class="t-earn">Basic Salary</th>
            <th class="t-np">No Pay</th>
            <th class="t-badj">Basic Adjusted Salary</th>
            <th class="t-epf">EPF Ee (<?php echo $epf_employee_rate;?>%)</th>
            <th class="t-epf">EPF Er (<?php echo $epf_employer_rate;?>%)</th>
            <th class="t-tot">Total EPF</th>
            <th class="t-att tc">Working<br>Days</th>
            <th class="t-nm tc">Source</th>
        </tr></thead>
        <tbody id="etb">
        <?php $i=0; foreach($employees as $emp): $eid=(int)$emp['id']; $r=$rows[$eid]; $i++;?>
        <tr data-s="<?php echo strtolower(htmlspecialchars(($emp['id_number']??'').' '.($emp['epf_number']??'').' '.($emp['employee_full_name']??'').' '.($emp['employee_id']??'').' '.($emp['company_name']??'').' '.($emp['designation_name']??'')));?>">
            <td class="tc" style="color:#94a3b8;font-size:11px;"><?php echo $i;?></td>
            <td class="tc"><?php $nd=strtoupper(trim($emp['id_number']??''));echo $nd?'<span class="nic-c">'.htmlspecialchars($nd).'</span>':'<span class="m-z">—</span>';?></td>
            <td class="tl"><div class="sn"><?php echo htmlspecialchars($r['surname']);?></div></td>
            <td class="tc"><span style="font-family:'Courier New',monospace;font-weight:700;font-size:12px;color:#0369a1;letter-spacing:1px;"><?php echo htmlspecialchars($r['initials']);?></span></td>
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
            <td><?php echo $r['basic']>0?'<span class="m-earn">'.number_format($r['basic'],2).'</span>':'<span class="m-z">—</span>';?></td>
            <td><?php echo $r['no_pay']>0?'<span class="m-np">'.number_format($r['no_pay'],2).'</span>':'<span class="m-z">—</span>';?></td>
            <td><span class="m-badj"><?php echo number_format($r['basic_adjusted'],2);?></span></td>
            <td><?php echo $r['epf_emp']>0?'<span class="m-ee">'.number_format($r['epf_emp'],2).'</span>':'<span class="m-z">—</span>';?></td>
            <td><?php echo $r['epf_er']>0?'<span class="m-er">'.number_format($r['epf_er'],2).'</span>':'<span class="m-z">—</span>';?></td>
            <td><?php echo $r['total_epf']>0?'<span class="m-tot">'.number_format($r['total_epf'],2).'</span>':'<span class="m-z">—</span>';?></td>
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
            <td colspan="8" style="text-align:right;color:#7dd3fc;font-size:11px;"><i class="fa-solid fa-sigma" style="margin-right:5px;"></i>GRAND TOTAL (<?php echo count($employees);?> employees)</td>
            <td style="color:#a5f3fc;"><?php echo number_format($totals['basic'],2);?></td>
            <td style="color:#fca5a5;"><?php echo number_format($totals['no_pay'],2);?></td>
            <td style="color:#c7d2fe;"><?php echo number_format($totals['basic_adjusted'],2);?></td>
            <td style="color:#7dd3fc;"><?php echo number_format($totals['epf_emp'],2);?></td>
            <td style="color:#7dd3fc;"><?php echo number_format($totals['epf_er'],2);?></td>
            <td style="color:#c4b5fd;font-size:13px;"><?php echo number_format($totals['total_epf'],2);?></td>
            <td></td><td></td>
        </tr>
        </tbody>
    </table>
    <div id="nr" style="text-align:center;padding:38px;color:#94a3b8;display:none;"><i class="fa-solid fa-magnifying-glass" style="font-size:22px;margin-bottom:7px;display:block;"></i>No results match.</div>
    <?php endif;?>
    </div>
</div>

<!-- TXT FORMAT INFO -->
<div style="margin-top:14px;background:#fff;border:1px solid #e2e8f0;border-radius:9px;padding:13px 16px;font-size:12px;color:#475569;">
    <div style="font-weight:800;color:#0c4a6e;margin-bottom:7px;"><i class="fa-solid fa-file-lines"></i> TXT Fixed-Width Format — <?php echo $contrib_period;?></div>
    <div style="display:flex;gap:18px;flex-wrap:wrap;">
        <span><strong>NIC</strong> 20 chars</span><span><strong>Surname</strong> last word, UPPERCASE, 36 chars</span>
        <span><strong>Initials</strong> first letter of preceding words, 24 chars</span>
        <span><strong>EPF No.</strong> right-aligned 6 chars</span>
        <span><strong>Total Earnings</strong> Basic Adjusted Salary (Basic − No Pay)</span>
        <span><strong>Working Days</strong> att_days from salary sheet</span>
        <span><strong>Employer No.</strong> <?php echo htmlspecialchars($employer_number?:'(set in settings)');?></span>
        <span><strong>Period</strong> <?php echo $contrib_period;?></span>
    </div>
</div>

<!-- SAVE BATCH MODAL -->
<div class="mo" id="bm">
    <div class="mb">
        <div class="mh">
            <i class="fa-solid fa-floppy-disk" style="font-size:20px;"></i>
            <div>
                <h3><?php echo $existing_batch?'Update EPF Batch':'Save EPF Batch';?></h3>
                <div style="font-size:11px;opacity:.8;"><?php echo $active_period?$month_names[$active_period['month']].' '.$active_period['year']:'';?> · <?php echo count($employees);?> employees</div>
            </div>
        </div>
        <div class="mbd">
            <?php if($existing_batch):?>
            <p style="background:#fef3c7;border:1px solid #fde68a;border-radius:7px;padding:9px 13px;color:#92400e;font-weight:600;"><i class="fa-solid fa-triangle-exclamation"></i> A batch already exists for this period. Saving will <strong>replace</strong> the existing batch.</p>
            <?php else:?>
            <p>Save the current EPF data as a batch for <strong><?php echo $active_period?$month_names[$active_period['month']].' '.$active_period['year']:'this period';?></strong>. Working days from the salary sheet will be stored per employee.</p>
            <?php endif;?>
            <div class="mrow">
                <div class="mf"><label>Employees</label><input type="text" value="<?php echo count($employees);?>" readonly style="background:#f8fafc;"></div>
                <div class="mf"><label>Total EPF Amount</label><input type="text" value="<?php echo number_format($totals['total_epf'],2);?>" readonly style="background:#f8fafc;font-weight:700;color:#7c3aed;"></div>
            </div>
            <div class="mrow">
                <div class="mf"><label>Total No Pay</label><input type="text" value="<?php echo number_format($totals['no_pay'],2);?>" readonly style="background:#f8fafc;color:#b91c1c;"></div>
                <div class="mf"><label>Basic Adjusted Salary</label><input type="text" value="<?php echo number_format($totals['basic_adjusted'],2);?>" readonly style="background:#f8fafc;color:#3730a3;"></div>
            </div>
            <div class="mrow">
                <div class="mf"><label>EPF Employee (<?php echo $epf_employee_rate;?>%)</label><input type="text" value="<?php echo number_format($totals['epf_emp'],2);?>" readonly style="background:#f8fafc;"></div>
                <div class="mf"><label>EPF Employer (<?php echo $epf_employer_rate;?>%)</label><input type="text" value="<?php echo number_format($totals['epf_er'],2);?>" readonly style="background:#f8fafc;"></div>
            </div>
            <div class="mf"><label>Notes (optional)</label><textarea id="bn" rows="2" placeholder="Any notes for this batch…" style="resize:none;"></textarea></div>
        </div>
        <div class="mft">
            <button class="btn-cn" onclick="closeModal()">Cancel</button>
            <button class="btn-ok" id="sb" onclick="saveBatch()">
                <i class="fa-solid fa-floppy-disk"></i> <?php echo $existing_batch?'Update Batch':'Save Batch';?>
            </button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
const EPF_ENTRIES=<?php
$ea=[];
foreach($employees as $emp){$eid=(int)$emp['id'];$r=$rows[$eid];$ea[]=['employee_id'=>$eid,'epf_number'=>$emp['epf_number'],'nic'=>strtoupper(trim($emp['id_number']??'')),'full_name'=>$emp['employee_full_name'],'surname'=>$r['surname'],'initials'=>$r['initials'],'basic'=>$r['basic'],'no_pay'=>$r['no_pay'],'basic_adjusted'=>$r['basic_adjusted'],'epf_emp'=>$r['epf_emp'],'epf_er'=>$r['epf_er'],'total_epf'=>$r['total_epf'],'att_days'=>$r['att_days'],'source'=>$r['source']];}
echo json_encode($ea,JSON_UNESCAPED_UNICODE);
?>;
const PID=<?php echo intval($sel_period_id);?>,TB=<?php echo floatval($totals['basic']);?>,TEE=<?php echo floatval($totals['epf_emp']);?>,TER=<?php echo floatval($totals['epf_er']);?>,TEPF=<?php echo floatval($totals['total_epf']);?>,EC=<?php echo count($employees);?>;

function openModal(){document.getElementById('bm').classList.add('open');}
function closeModal(){document.getElementById('bm').classList.remove('open');}

function saveBatch(){
    const btn=document.getElementById('sb');
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    const fd=new FormData();
    fd.append('action','save_batch');fd.append('period_id',PID);
    fd.append('total_employees',EC);fd.append('total_basic',TB);
    fd.append('total_epf_emp',TEE);fd.append('total_epf_er',TER);fd.append('total_epf',TEPF);
    fd.append('notes',document.getElementById('bn').value);
    fd.append('entries',JSON.stringify(EPF_ENTRIES));
    fetch('epf_report.php',{method:'POST',body:fd})
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
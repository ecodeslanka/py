<?php
/**
 * payroll_headers_lib.php — Payroll Headers: named salary additions / deductions, each ticked for the staff categories it is used for.
 *   payroll_headers            : id, name, header_type ('addition' | 'deduction'), default_amount, active, sort_order
 *   payroll_header_categories  : header_id, category_code (staff_categories.category_code)
 * The salary sheets (CC / SR / ACC / MR / Office / ST) offer these names in their Additional Earnings / Deductions rows.
 */
if (!function_exists('ph_ensure')) {
    function ph_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
    function ph_ensure($conn) {
        static $done = false; if ($done) return; $done = true;
        ph_q($conn, "CREATE TABLE IF NOT EXISTS payroll_headers (
            id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, header_type VARCHAR(10) NOT NULL DEFAULT 'addition',
            default_amount DECIMAL(12,2) NULL, description VARCHAR(255) NULL, active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0,
            created_by VARCHAR(100) NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_by VARCHAR(100) NULL, updated_at DATETIME NULL,
            UNIQUE KEY uq_name_type (name, header_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach (['source' => "VARCHAR(20) NULL", 'component_key' => "VARCHAR(40) NULL"] as $c => $d) {      /* source 'salary_sheet' = a column the salary sheet calculates */
            $r = ph_q($conn, "SHOW COLUMNS FROM payroll_headers LIKE '$c'"); if ($r && mysqli_num_rows($r) === 0) ph_q($conn, "ALTER TABLE payroll_headers ADD COLUMN `$c` $d");
        }
        ph_q($conn, "CREATE TABLE IF NOT EXISTS payroll_header_categories (
            header_id INT NOT NULL, category_code VARCHAR(50) NOT NULL, PRIMARY KEY (header_id, category_code), INDEX idx_cat (category_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    /** active headers for a set of staff category codes → ['addition' => [[name, amount], …], 'deduction' => …] */
    function ph_for_categories($conn, $codes) {
        ph_ensure($conn);
        $codes = array_values(array_filter(array_map('trim', (array)$codes), 'strlen'));
        $o = ['addition' => [], 'deduction' => []]; if (!$codes) return $o;
        $in = implode(',', array_map(function ($c) use ($conn) { return "'" . mysqli_real_escape_string($conn, $c) . "'"; }, $codes));
        $r = ph_q($conn, "SELECT DISTINCT h.id, h.name, h.header_type, h.default_amount, h.sort_order FROM payroll_headers h
                          JOIN payroll_header_categories c ON c.header_id = h.id
                          WHERE h.active = 1 AND COALESCE(h.source,'') <> 'salary_sheet' AND c.category_code IN ($in) ORDER BY h.sort_order, h.name");   /* the sheet's own columns are not offered as Additional rows */
        while ($r && ($x = mysqli_fetch_assoc($r))) {
            $t = $x['header_type'] === 'deduction' ? 'deduction' : 'addition';
            $o[$t][] = ['name' => $x['name'], 'amount' => $x['default_amount'] !== null ? (float)$x['default_amount'] : null];
        }
        return $o;
    }
    /** the salary sheets: sheet → staff category codes */
    if (!defined('PH_SHEET_CATS')) define('PH_SHEET_CATS', ['CC' => ['CC', 'SACC'], 'ACC' => ['ACC'], 'SR' => ['SR'], 'MR' => ['MR'], 'OFF' => ['OFF'], 'ST' => ['ST']]);
    /** the components each salary sheet calculates: key → [name, type, sheets] (from the Total Earnings / Total Deductions of cc/acc/sr/mr/off/st_salary.php) */
    function ph_components() {
        $all = ['CC', 'ACC', 'SR', 'MR', 'OFF', 'ST'];
        return [
            'basic'        => ['Basic Salary', 'addition', $all],
            'ccfot'        => ['CCFOT', 'addition', ['CC', 'ACC', 'ST']],
            'credit_mgt'   => ['Credit Management', 'addition', ['CC']],
            'payee_chq'    => ['Payee Cheque', 'addition', ['CC']],
            'ssv'          => ['SSV', 'addition', ['CC', 'ACC', 'SR']],
            'att_inc'      => ['Attendance Incentive', 'addition', ['CC', 'MR']],
            'locus_admin'  => ['Locus Admin', 'addition', ['CC']],
            'att_allow'    => ['Attendance Allowance', 'addition', ['ACC', 'SR', 'OFF', 'ST']],
            'punctuality'  => ['Punctuality', 'addition', ['ACC', 'OFF', 'ST']],
            'disc'         => ['Discretionary Support', 'addition', ['SR']],
            'ps'           => ['PS Compliance Incentive', 'addition', ['SR']],
            'eco'          => ['ECO Incentive', 'addition', ['SR']],
            'bp'           => ['BP Incentive', 'addition', ['SR']],
            'assort'       => ['Assortment Incentive', 'addition', ['SR']],
            'other_inc'    => ['Other Incentive', 'addition', ['SR']],
            'plc'          => ['Placement', 'addition', ['MR']],
            'dlink'        => ['D-Link Amount', 'addition', ['MR']],
            'daily_inc'    => ['Daily Incentive', 'addition', ['OFF']],
            'weekly_inc'   => ['Weekly Incentive', 'addition', ['OFF']],
            'monthly_inc'  => ['Monthly Incentive', 'addition', ['OFF']],
            'stores_dmg'   => ['Stores Damage', 'addition', ['ST']],
            'rsqm'         => ['RSQM', 'addition', ['ST']],
            'load_unload'  => ['Loading / Unloading', 'addition', ['ST']],
            'meal'         => ['Meal Reimbursement', 'addition', $all],
            'travel'       => ['Travel Reimbursement', 'addition', ['SR', 'MR']],
            'fuel'         => ['Fuel', 'addition', ['SR']],
            'mobile'       => ['Mobile Reimbursement', 'addition', ['CC', 'SR']],
            'arrears'      => ['Arrears', 'addition', $all],
            'epf8'         => ['EPF 8%', 'deduction', $all],
            'welfare'      => ['Welfare', 'deduction', $all],
            'w_loan'       => ['Welfare Loan', 'deduction', $all],
            'w_soc'        => ['Welfare Society', 'deduction', $all],
            'donations'    => ['Donations', 'deduction', $all],
            'sal_adv'      => ['Salary Advance', 'deduction', $all],
            'loan'         => ['Loan Deduction', 'deduction', $all],
            'credit_rec'   => ['Credit Recovery', 'deduction', $all],
            'cash_short'   => ['Cash Short', 'deduction', ['CC', 'ACC']],
            'good_short'   => ['Good Short', 'deduction', ['CC', 'ACC']],
            'no_pay'       => ['No Pay', 'deduction', $all],
            'retention'    => ['Retention', 'deduction', $all],
            'excess'       => ['Excess Payment', 'deduction', $all],
        ];
    }
    /** prints the two lists the salary sheets use (<datalist id="phEarnList"> / "phDedList") and the script that fills a default amount */
    function ph_salary_lists($conn, $codes) {
        $h = ph_for_categories($conn, $codes);
        $opt = function ($list) { $s = ''; foreach ($list as $x) $s .= '<option value="' . htmlspecialchars($x['name'], ENT_QUOTES, 'UTF-8') . '"></option>'; return $s; };
        $amounts = [];
        foreach (['addition' => 'E', 'deduction' => 'D'] as $t => $k) foreach ($h[$t] as $x) if ($x['amount'] !== null && $x['amount'] > 0) $amounts[$k . '|' . mb_strtolower($x['name'])] = $x['amount'];
        echo '<datalist id="phEarnList">' . $opt($h['addition']) . '</datalist><datalist id="phDedList">' . $opt($h['deduction']) . '</datalist>';
        echo '<script>(function(){var A=' . json_encode($amounts) . ';'
           . 'document.addEventListener("change",function(e){var i=e.target;if(!i||!i.classList||!i.classList.contains("add-row-desc"))return;'
           . 'var k=(i.getAttribute("list")==="phDedList"?"D":"E")+"|"+String(i.value||"").trim().toLowerCase();if(!(k in A))return;'
           . 'var row=i.closest(".add-row-item")||i.parentElement,a=row?row.querySelector(".add-row-amount"):null;'
           . 'if(a&&!(parseFloat(a.value)>0)){a.value=A[k];a.dispatchEvent(new Event("input",{bubbles:true}));a.dispatchEvent(new Event("change",{bubbles:true}));}});})();</script>';
    }
}

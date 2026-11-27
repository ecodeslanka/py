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
                          WHERE h.active = 1 AND c.category_code IN ($in) ORDER BY h.sort_order, h.name");
        while ($r && ($x = mysqli_fetch_assoc($r))) {
            $t = $x['header_type'] === 'deduction' ? 'deduction' : 'addition';
            $o[$t][] = ['name' => $x['name'], 'amount' => $x['default_amount'] !== null ? (float)$x['default_amount'] : null];
        }
        return $o;
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

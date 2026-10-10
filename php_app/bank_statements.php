<?php
include 'config.php';
require_once __DIR__ . '/bank_manual_recon_lib.php';

/* ══════════════════════════════════════════════════════════
   AUTO-CREATE TABLES
══════════════════════════════════════════════════════════ */
mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS `bank_statement_uploads` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `account_id`        INT UNSIGNED NOT NULL,
    `statement_date`    DATE         NOT NULL,
    `customer_id`       VARCHAR(50)  NULL,
    `customer_name`     VARCHAR(255) NULL,
    `account_number`    VARCHAR(100) NULL,
    `original_filename` VARCHAR(255) NOT NULL,
    `total_rows`        INT UNSIGNED NOT NULL DEFAULT 0,
    `bank_type`         VARCHAR(10)  NOT NULL DEFAULT 'BOC',
    `uploaded_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_account`   (`account_id`),
    KEY `idx_stmt_date` (`statement_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Add bank_type column if it doesn't exist (for existing installations)
mysqli_query($conn, "ALTER TABLE `bank_statement_uploads` ADD COLUMN IF NOT EXISTS `bank_type` VARCHAR(10) NOT NULL DEFAULT 'BOC' AFTER `total_rows`");

mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS `bank_statement_transactions` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `upload_id`        INT UNSIGNED NOT NULL,
    `transaction_date` DATE          NULL,
    `value_date`       DATE          NULL,
    `description`      TEXT          NULL,
    `debit`            DECIMAL(15,2) NULL,
    `credit`           DECIMAL(15,2) NULL,
    `balance`          DECIMAL(15,2) NULL,
    `branch_code`      VARCHAR(50)   NULL,
    `cheque_no`        VARCHAR(50)   NULL,
    `serial_no`        VARCHAR(50)   NULL,
    `reference`        VARCHAR(255)  NULL,
    PRIMARY KEY (`id`),
    KEY `idx_upload`   (`upload_id`),
    KEY `idx_txn_date` (`transaction_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Add reference column if it doesn't exist (for existing installations)
mysqli_query($conn, "ALTER TABLE `bank_statement_transactions` ADD COLUMN IF NOT EXISTS `reference` VARCHAR(255) NULL AFTER `serial_no`");

// Reconcile status / category / remark are written by the reconcile pages
// (DP Deposit Bank Recon, Cheque Reconciliation …) and by the "Reconcile"
// button on a not-reconciled line (Manual Reconcile with a reason,
// saved through bank_manual_recon_api.php).
bmr_ensure($conn);


/* ══════════════════════════════════════════════════════════
   HELPERS
══════════════════════════════════════════════════════════ */
function safeAmount($v) {
    $v = trim(str_replace([',', ' '], '', (string)$v));
    if ($v === '' || $v === '-' || $v === '--' || strtolower($v) === 'nan') return null;
    return is_numeric($v) ? (float)$v : null;
}

function safeDate($v) {
    $v = trim((string)$v);
    if ($v === '' || $v === 'nan') return null;
    // Excel serial number
    if (is_numeric($v) && (float)$v > 1000) {
        $unix = ((float)$v - 25569) * 86400;
        return $unix > 0 ? date('Y-m-d', (int)$unix) : null;
    }
    foreach (['d-m-Y','Y-m-d','d/m/Y','m/d/Y','d-M-Y','d M Y','d M Y'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $v);
        if ($dt) return $dt->format('Y-m-d');
    }
    $t = strtotime($v);
    return $t ? date('Y-m-d', $t) : null;
}

function safeStr($v, $max = 255) {
    $s = trim((string)$v);
    return ($s === '' || strtolower($s) === 'nan') ? null : mb_substr($s, 0, $max);
}

function dbEsc($conn, $v) {
    return $v === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, $v) . "'";
}

function bankTypeColor($bt) {
    $bt = strtoupper($bt);
    if ($bt === 'NDB')     return '#7c3aed';
    if ($bt === 'SAMPATH') return '#b91c1c';
    return '#1e40af'; // BOC
}


/* ══════════════════════════════════════════════════════════
   XLS PARSER — pure PHP, no Composer, no Python
══════════════════════════════════════════════════════════ */
function parseXls($path) {
    $data = file_get_contents($path);
    if ($data === false) return ['error' => 'Cannot read file'];
 
    if (substr($data, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
        return ['error' => 'Not a valid .xls (OLE) file'];
    }
 
    $sector_size        = 1 << unpack('v', substr($data, 30, 2))[1];
    $mini_sector_size   = 1 << unpack('v', substr($data, 32, 2))[1];
    $num_fat_sectors    = unpack('V', substr($data, 44, 4))[1];
    $dir_start          = unpack('V', substr($data, 48, 4))[1];
    $mini_stream_cutoff = unpack('V', substr($data, 56, 4))[1];
    $mini_fat_start     = unpack('V', substr($data, 60, 4))[1];
 
    $fat_sectors = [];
    for ($i = 0; $i < 109 && $i < $num_fat_sectors; $i++) {
        $fat_sectors[] = unpack('V', substr($data, 76 + $i * 4, 4))[1];
    }
    $difat_start = unpack('V', substr($data, 68, 4))[1];
    $difat_count = unpack('V', substr($data, 72, 4))[1];
    $difat_sec   = $difat_start;
    for ($d = 0; $d < $difat_count && $difat_sec < 0xFFFFFFFE; $d++) {
        $sec_off = ($difat_sec + 1) * $sector_size;
        for ($i = 0; $i < ($sector_size / 4) - 1; $i++) {
            $fat_sectors[] = unpack('V', substr($data, $sec_off + $i * 4, 4))[1];
        }
        $difat_sec = unpack('V', substr($data, $sec_off + $sector_size - 4, 4))[1];
    }
 
    $fat = [];
    foreach ($fat_sectors as $fs) {
        if ($fs >= 0xFFFFFFFE) continue;
        $off = ($fs + 1) * $sector_size;
        for ($i = 0; $i + 4 <= $sector_size; $i += 4) {
            $fat[] = unpack('V', substr($data, $off + $i, 4))[1];
        }
    }
 
    $read_chain = function($start) use ($data, $fat, $sector_size) {
        $result = ''; $sec = $start; $visited = [];
        while ($sec < 0xFFFFFFFE && !isset($visited[$sec])) {
            $visited[$sec] = 1;
            $off = ($sec + 1) * $sector_size;
            $result .= substr($data, $off, $sector_size);
            $sec = isset($fat[$sec]) ? $fat[$sec] : 0xFFFFFFFE;
        }
        return $result;
    };
 
    $mini_fat = [];
    if ($mini_fat_start < 0xFFFFFFFE) {
        $mfat_data = $read_chain($mini_fat_start);
        for ($i = 0; $i + 4 <= strlen($mfat_data); $i += 4) {
            $mini_fat[] = unpack('V', substr($mfat_data, $i, 4))[1];
        }
    }
 
    $dir_data = $read_chain($dir_start);
    $entries  = [];
    for ($i = 0; $i + 128 <= strlen($dir_data); $i += 128) {
        $entry    = substr($dir_data, $i, 128);
        $name_len = unpack('v', substr($entry, 64, 2))[1];
        $name = '';
        if ($name_len > 2) {
            for ($j = 0; $j < $name_len - 2; $j += 2) $name .= chr(ord($entry[$j]) & 0x7F);
        }
        $type  = ord($entry[66]);
        $start = unpack('V', substr($entry, 116, 4))[1];
        $size  = unpack('V', substr($entry, 120, 4))[1];
        $entries[] = compact('name', 'type', 'start', 'size');
    }
 
    $root_start  = $entries[0]['start'] ?? 0xFFFFFFFE;
    $mini_stream = ($root_start < 0xFFFFFFFE) ? $read_chain($root_start) : '';
 
    $read_stream = function($start, $size) use ($read_chain, $mini_stream, $mini_fat, $mini_sector_size, $mini_stream_cutoff, $sector_size) {
        if ($size < $mini_stream_cutoff && strlen($mini_stream) > 0) {
            $result = ''; $sec = $start; $visited = [];
            while ($sec < 0xFFFFFFFE && !isset($visited[$sec])) {
                $visited[$sec] = 1;
                $off = $sec * $mini_sector_size;
                $result .= substr($mini_stream, $off, $mini_sector_size);
                $sec = isset($mini_fat[$sec]) ? $mini_fat[$sec] : 0xFFFFFFFE;
            }
            return substr($result, 0, $size);
        }
        return substr($read_chain($start), 0, $size);
    };
 
    $wb_stream = null;
    foreach ($entries as $e) {
        if (($e['type'] === 2 || $e['type'] === 5) && in_array(strtolower($e['name']), ['workbook', 'book'])) {
            $wb_stream = $read_stream($e['start'], $e['size']);
            break;
        }
    }
    if ($wb_stream === null) return ['error' => 'No Workbook stream found'];
 
    $pos   = 0;
    $len   = strlen($wb_stream);
    $sst   = [];
    $matrix = [];
    $xf_formats = [];
    $num_formats = [];
    $builtin_date_fmts = [14, 15, 16, 17, 18, 19, 20, 21, 22, 45, 46, 47, 49];
 
    $is_date_xf = function($xf_idx) use (&$xf_formats, &$num_formats, $builtin_date_fmts) {
        $fmt_id  = $xf_formats[$xf_idx] ?? -1;
        if (in_array($fmt_id, $builtin_date_fmts)) return true;
        $fmt_str = strtolower($num_formats[$fmt_id] ?? '');
        return (strpos($fmt_str, 'y') !== false || strpos($fmt_str, 'd') !== false)
               && strpos($fmt_str, '"') === false;
    };
 
    $serial_to_date = function($n) {
        if (!is_numeric($n) || $n <= 0) return null;
        $unix = ((float)$n - 25569) * 86400;
        return $unix > 0 ? date('Y-m-d', (int)$unix) : null;
    };
 
    while ($pos + 4 <= $len) {
        $rec_type = unpack('v', substr($wb_stream, $pos, 2))[1];
        $rec_len  = unpack('v', substr($wb_stream, $pos + 2, 2))[1];
        $pos += 4;
 
        /* ── For SST we keep segments SEPARATE to handle CONTINUE boundaries ── */
        if ($rec_type === 0x00FC) {
            $sst_segments = [substr($wb_stream, $pos, $rec_len)];
            $pos += $rec_len;
            while ($pos + 4 <= $len) {
                $next_type = unpack('v', substr($wb_stream, $pos, 2))[1];
                if ($next_type !== 0x003C) break;
                $next_len  = unpack('v', substr($wb_stream, $pos + 2, 2))[1];
                $sst_segments[] = substr($wb_stream, $pos + 4, $next_len);
                $pos += 4 + $next_len;
            }
 
            $boundaries = [];
            $cum = 0;
            foreach (array_slice($sst_segments, 0, -1) as $seg) {
                $cum += strlen($seg);
                $boundaries[$cum] = true;
            }
            $all_data = implode('', $sst_segments);
            $data_len = strlen($all_data);
 
            $total = unpack('V', substr($all_data, 4, 4))[1];
            $p     = 8;
 
            for ($s = 0; $s < $total; $s++) {
                if ($p + 2 > $data_len) break;
                $char_count  = unpack('v', substr($all_data, $p, 2))[1]; $p += 2;
                if ($p >= $data_len) break;
                $flags       = ord($all_data[$p]); $p++;
                $cur_unicode = ($flags & 0x01);
                $has_ext     = ($flags & 0x04);
                $has_rich    = ($flags & 0x08);
                $rich_count  = 0; $ext_len = 0;
                if ($has_rich) { $rich_count = unpack('v', substr($all_data, $p, 2))[1]; $p += 2; }
                if ($has_ext)  { $ext_len   = unpack('V', substr($all_data, $p, 4))[1]; $p += 4; }
 
                $remaining  = $char_count;
                $str_result = '';
 
                while ($remaining > 0) {
                    $next_b = PHP_INT_MAX;
                    foreach ($boundaries as $b => $_) {
                        if ($b > $p && $b < $next_b) $next_b = $b;
                    }
 
                    if ($cur_unicode) {
                        $bytes_avail  = $next_b === PHP_INT_MAX ? ($remaining * 2) : ($next_b - $p);
                        $chars_avail  = intdiv($bytes_avail, 2);
                        $chars_to_read = min($remaining, $chars_avail);
                        $chunk_bytes  = $chars_to_read * 2;
                        $str_result  .= mb_convert_encoding(substr($all_data, $p, $chunk_bytes), 'UTF-8', 'UTF-16LE');
                        $p           += $chunk_bytes;
                        $remaining   -= $chars_to_read;
                    } else {
                        $bytes_avail  = $next_b === PHP_INT_MAX ? $remaining : ($next_b - $p);
                        $chars_to_read = min($remaining, $bytes_avail);
                        $str_result  .= mb_convert_encoding(substr($all_data, $p, $chars_to_read), 'UTF-8', 'Windows-1252');
                        $p           += $chars_to_read;
                        $remaining   -= $chars_to_read;
                    }
 
                    if (isset($boundaries[$p]) && $remaining > 0) {
                        $new_flags   = ord($all_data[$p]); $p++;
                        $cur_unicode = ($new_flags & 0x01);
                    }
                }
 
                $p    += $rich_count * 4 + $ext_len;
                $sst[] = $str_result;
            }
            continue;
        }
 
        $rec_data = substr($wb_stream, $pos, $rec_len);
        $pos += $rec_len;
        while ($pos + 4 <= $len) {
            $next_type = unpack('v', substr($wb_stream, $pos, 2))[1];
            if ($next_type !== 0x003C) break;
            $next_len  = unpack('v', substr($wb_stream, $pos + 2, 2))[1];
            $rec_data .= substr($wb_stream, $pos + 4, $next_len);
            $pos += 4 + $next_len;
        }
 
        switch ($rec_type) {
            case 0x00E0:
                if (strlen($rec_data) >= 4) $xf_formats[] = unpack('v', substr($rec_data, 2, 2))[1];
                break;
            case 0x041E:
                if (strlen($rec_data) >= 3) {
                    $fmt_id    = unpack('v', substr($rec_data, 0, 2))[1];
                    $cch       = unpack('v', substr($rec_data, 2, 2))[1];
                    $is_u      = ord($rec_data[4] ?? "\x00") & 0x01;
                    $str_bytes = substr($rec_data, 5, $is_u ? $cch * 2 : $cch);
                    $num_formats[$fmt_id] = $is_u
                        ? mb_convert_encoding($str_bytes, 'UTF-8', 'UTF-16LE')
                        : mb_convert_encoding($str_bytes, 'UTF-8', 'Windows-1252');
                }
                break;
            case 0x0201:
                $matrix[unpack('v', substr($rec_data, 0, 2))[1]][unpack('v', substr($rec_data, 2, 2))[1]] = '';
                break;
            case 0x0203:
                $row = unpack('v', substr($rec_data, 0, 2))[1];
                $col = unpack('v', substr($rec_data, 2, 2))[1];
                $xf  = unpack('v', substr($rec_data, 4, 2))[1];
                $num = unpack('d',  substr($rec_data, 6, 8))[1];
                $matrix[$row][$col] = $is_date_xf($xf) ? $serial_to_date($num) : $num;
                break;
            case 0x0205:
                $matrix[unpack('v', substr($rec_data, 0, 2))[1]][unpack('v', substr($rec_data, 2, 2))[1]]
                    = ord($rec_data[6] ?? "\x00") ? 'TRUE' : 'FALSE';
                break;
            case 0x027E:
                $row = unpack('v', substr($rec_data, 0, 2))[1];
                $col = unpack('v', substr($rec_data, 2, 2))[1];
                $xf  = unpack('v', substr($rec_data, 4, 2))[1];
                $rk  = unpack('V',  substr($rec_data, 6, 4))[1];
                $div100 = ($rk & 0x01); $is_int = ($rk & 0x02);
                if ($is_int) { $val = $rk >> 2; }
                else { $rk_bytes = pack('V', $rk & 0xFFFFFFFC) . "\x00\x00\x00\x00"; $val = unpack('d', $rk_bytes)[1]; }
                if ($div100) $val /= 100;
                $matrix[$row][$col] = $is_date_xf($xf) ? $serial_to_date($val) : $val;
                break;
            case 0x00FD:
                $row    = unpack('v', substr($rec_data, 0, 2))[1];
                $col    = unpack('v', substr($rec_data, 2, 2))[1];
                $sst_ix = unpack('V', substr($rec_data, 6, 4))[1];
                $matrix[$row][$col] = $sst[$sst_ix] ?? '';
                break;
            case 0x0204:
                $row   = unpack('v', substr($rec_data, 0, 2))[1];
                $col   = unpack('v', substr($rec_data, 2, 2))[1];
                $cch   = unpack('v', substr($rec_data, 6, 2))[1];
                $is_u  = isset($rec_data[8]) ? (ord($rec_data[8]) & 0x01) : 0;
                $str_b = substr($rec_data, 9, $is_u ? $cch * 2 : $cch);
                $matrix[$row][$col] = $is_u
                    ? mb_convert_encoding($str_b, 'UTF-8', 'UTF-16LE')
                    : mb_convert_encoding($str_b, 'UTF-8', 'Windows-1252');
                break;
            case 0x00BE:
                $row = unpack('v', substr($rec_data, 0, 2))[1];
                $col = unpack('v', substr($rec_data, 2, 2))[1];
                $p2  = 4;
                while ($p2 + 6 <= strlen($rec_data) - 2) {
                    $xf  = unpack('v', substr($rec_data, $p2, 2))[1];
                    $rk  = unpack('V', substr($rec_data, $p2 + 2, 4))[1];
                    $div100 = ($rk & 0x01); $is_int = ($rk & 0x02);
                    if ($is_int) { $val = $rk >> 2; }
                    else { $rk_bytes = pack('V', $rk & 0xFFFFFFFC) . "\x00\x00\x00\x00"; $val = unpack('d', $rk_bytes)[1]; }
                    if ($div100) $val /= 100;
                    $matrix[$row][$col] = $is_date_xf($xf) ? $serial_to_date($val) : $val;
                    $col++; $p2 += 6;
                }
                break;
            case 0x00BF:
                $row    = unpack('v', substr($rec_data, 0, 2))[1];
                $col    = unpack('v', substr($rec_data, 2, 2))[1];
                $last_c = unpack('v', substr($rec_data, strlen($rec_data) - 2, 2))[1];
                for ($c = $col; $c <= $last_c; $c++) $matrix[$row][$c] = '';
                break;
            case 0x00D6:
                $row = unpack('v', substr($rec_data, 0, 2))[1];
                $col = unpack('v', substr($rec_data, 2, 2))[1];
                $cch = unpack('v', substr($rec_data, 6, 2))[1];
                $matrix[$row][$col] = substr($rec_data, 8, $cch);
                break;
        }
    }
 
    ksort($matrix);
    foreach ($matrix as &$row) ksort($row);
    return $matrix;
}

/* ══════════════════════════════════════════════════════════
   SAMPATH BANK PARSER — HTML table saved as .xls
   Cols: 0=Txn Date, 1=Tran ID, 2=Tran Serial, 3=Description,
         4=Cr/Dr, 5=Amount, 6=Balance
══════════════════════════════════════════════════════════ */
function parseSampathHtml($path) {
    $html = file_get_contents($path);
    if ($html === false || trim($html) === '') return ['error' => 'Cannot read file'];

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    if (!$loaded) return ['error' => 'Cannot parse Sampath statement HTML.'];

    $matrix = [];
    $ri = 0;
    foreach ($dom->getElementsByTagName('tr') as $tr) {
        $cols = [];
        $ci = 0;
        foreach ($tr->childNodes as $cell) {
            $tag = strtolower($cell->nodeName ?? '');
            if ($tag !== 'td' && $tag !== 'th') continue;
            $text = trim(preg_replace('/\s+/', ' ', $cell->textContent));
            if (mb_strlen($text) >= 2 && mb_substr($text, 0, 1) === "'" && mb_substr($text, -1) === "'") {
                $text = mb_substr($text, 1, mb_strlen($text) - 2);
            }
            $cols[$ci] = $text;
            $ci++;
        }
        if ($cols) { $matrix[$ri] = $cols; $ri++; }
    }

    if (!$matrix) return ['error' => 'No rows found in Sampath statement.'];
    return $matrix;
}

/* ══════════════════════════════════════════════════════════
   FILE → MATRIX
══════════════════════════════════════════════════════════ */
function parseToMatrix($tmp, $ext, $bank_type) {
    if ($bank_type === 'SAMPATH') return parseSampathHtml($tmp);
    if ($ext === 'xls') return parseXls($tmp);

    $matrix = [];
    if ($ext === 'xlsx') {
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) return ['error' => 'Cannot open XLSX file.'];
        $shared_xml = $zip->getFromName('xl/sharedStrings.xml');
        $sheet_xml  = $zip->getFromName('xl/worksheets/sheet1.xml');
        $styles_xml = $zip->getFromName('xl/styles.xml');
        $zip->close();
        if (!$sheet_xml) return ['error' => 'Cannot read sheet data from XLSX.'];

        $shared = [];
        if ($shared_xml) {
            libxml_use_internal_errors(true);
            $sxml = simplexml_load_string($shared_xml);
            if ($sxml) {
                foreach ($sxml->si as $si) {
                    $t = '';
                    if (isset($si->t)) { $t = (string)$si->t; }
                    elseif (isset($si->r)) { foreach ($si->r as $r) { if (isset($r->t)) $t .= (string)$r->t; } }
                    $shared[] = $t;
                }
            }
        }
        $date_xf = [];
        if ($styles_xml) {
            libxml_use_internal_errors(true);
            $stxml = simplexml_load_string($styles_xml);
            if ($stxml && isset($stxml->cellXfs)) {
                $builtin_d = [14,15,16,17,18,19,20,21,22,45,46,47];
                $custom_d  = [];
                if (isset($stxml->numFmts)) {
                    foreach ($stxml->numFmts->numFmt as $nf) {
                        $id=$nf['numFmtId']; $fmt=strtolower((string)$nf['formatCode']);
                        if (strpos($fmt,'y')!==false||strpos($fmt,'d')!==false) $custom_d[]=(int)$id;
                    }
                }
                $xi=0;
                foreach ($stxml->cellXfs->xf as $xf) {
                    $nfid=(int)$xf['numFmtId'];
                    if (in_array($nfid,$builtin_d)||in_array($nfid,$custom_d)) $date_xf[]=$xi;
                    $xi++;
                }
            }
        }
        $serial_to_date = function($s) { $unix=((float)$s-25569)*86400; return $unix>0?date('Y-m-d',(int)$unix):null; };
        $col_idx = function($letters) { $letters=strtoupper($letters);$index=0; for($i=0;$i<strlen($letters);$i++) $index=$index*26+(ord($letters[$i])-64); return $index-1; };
        libxml_use_internal_errors(true);
        $sxml = simplexml_load_string($sheet_xml);
        if (!$sxml) return ['error' => 'Failed to parse XLSX sheet XML.'];
        foreach ($sxml->sheetData->row as $row) {
            $rnum = (int)$row['r'];
            foreach ($row->c as $cell) {
                preg_match('/^([A-Z]+)/', (string)$cell['r'], $m);
                $ci=$col_idx($m[1]); $t=(string)$cell['t']; $sidx=isset($cell['s'])?(int)$cell['s']:-1;
                $raw=isset($cell->v)?(string)$cell->v:'';
                if ($t==='s') { $val=$shared[(int)$raw]??''; }
                elseif (in_array($sidx,$date_xf)&&is_numeric($raw)) { $val=$serial_to_date($raw); }
                else { $val=$raw; }
                $matrix[$rnum-1][$ci]=$val;
            }
        }
        return $matrix;
    }

    if ($ext === 'csv') {
        $row_i = 0;
        if (($fh = fopen($tmp,'r')) === false) return ['error' => 'Cannot read CSV file.'];
        while (($row = fgetcsv($fh)) !== false) { $matrix[$row_i]=array_values($row); $row_i++; }
        fclose($fh);
        return $matrix;
    }
    return ['error' => 'Unsupported file type.'];
}

/* ══════════════════════════════════════════════════════════
   MATRIX → NORMALISED ROWS (bank specific)
   Each row: td, vd, desc, debit, credit, bal, br, ch, sr, ref
══════════════════════════════════════════════════════════ */
function matrixToRows($matrix, $bank_type) {
    $meta = ['customer_id' => '', 'customer_name' => '', 'account_number' => ''];
    $rows = [];
    $skipped = 0;
    $header_row = null;

    if ($bank_type === 'BOC') {
        /* Rows 1–11 metadata, row 12 header, row 13+ data
           Cols: 0=TxnDate,1=ValueDate,2=Desc,3=Debit,4=Credit,5=Balance,6=Branch,7=Cheque,8=Serial */
        foreach ($matrix as $ri => $row) {
            $c0 = trim((string)($row[0] ?? ''));
            if (stripos($c0,'Customer Id') !== false) {
                $meta['customer_id']   = trim((string)($row[2] ?? ''));
                $meta['customer_name'] = trim((string)($row[6] ?? ''));
            }
            if (stripos($c0,'Account Number') !== false) {
                $meta['account_number'] = trim((string)($row[2] ?? ''));
            }
            if ($ri >= 11) break;
        }
        foreach ($matrix as $ri => $row) {
            if (trim((string)($row[0] ?? '')) === 'Transaction Date') { $header_row = $ri; break; }
        }
        if ($header_row === null) return ['error' => 'Could not find "Transaction Date" header row. Check the BOC file format.'];

        foreach ($matrix as $ri => $row) {
            if ($ri <= $header_row) continue;
            $td = safeDate($row[0] ?? '');
            if (!$td) { $skipped++; continue; }
            $rows[] = [
                'td'     => $td,
                'vd'     => safeDate($row[1] ?? ''),
                'desc'   => safeStr($row[2] ?? '', 500),
                'debit'  => safeAmount($row[3] ?? ''),
                'credit' => safeAmount($row[4] ?? ''),
                'bal'    => safeAmount($row[5] ?? ''),
                'br'     => safeStr($row[6] ?? '', 50),
                'ch'     => safeStr($row[7] ?? '', 50),
                'sr'     => safeStr($row[8] ?? '', 50),
                'ref'    => null,
            ];
        }
    }
    elseif ($bank_type === 'NDB') {
        /* CSV. Header row "Transaction Date…", opening balance row, data, last row = closing balance.
           Debit col negative → abs() */
        foreach ($matrix as $ri => $row) {
            $c0 = trim((string)($row[0] ?? ''));
            if (stripos($c0,'Account Number') !== false)  $meta['account_number'] = trim((string)($row[1] ?? ''));
            if (stripos($c0,'Owning Customer') !== false) $meta['customer_name']  = trim((string)($row[1] ?? ''));
            if ($ri >= 12) break;
        }
        foreach ($matrix as $ri => $row) {
            if (trim((string)($row[0] ?? '')) === 'Transaction Date') { $header_row = $ri; break; }
        }
        if ($header_row === null) return ['error' => 'Could not find "Transaction Date" header row. Check the NDB file format.'];

        $all_rows = array_keys($matrix);
        $last_ri  = end($all_rows);

        foreach ($matrix as $ri => $row) {
            if ($ri <= $header_row) continue;
            if ($ri === $last_ri) { $skipped++; continue; }
            $td = safeDate($row[0] ?? '');
            if (!$td) { $skipped++; continue; }

            $raw_debit  = safeAmount($row[4] ?? '');
            $raw_credit = safeAmount($row[5] ?? '');
            $debit  = ($raw_debit  !== null && $raw_debit  < 0) ? abs($raw_debit)  : (($raw_debit  !== null && $raw_debit  > 0) ? $raw_debit  : null);
            $credit = ($raw_credit !== null && $raw_credit > 0) ? $raw_credit : null;

            $rows[] = [
                'td'     => $td,
                'vd'     => safeDate($row[1] ?? ''),
                'desc'   => safeStr($row[2] ?? '', 500),
                'debit'  => $debit,
                'credit' => $credit,
                'bal'    => safeAmount($row[6] ?? ''),
                'br'     => null,
                'ch'     => null,
                'sr'     => null,
                'ref'    => safeStr($row[3] ?? '', 255),
            ];
        }
    }
    elseif ($bank_type === 'SAMPATH') {
        /* Header: Txn Date | Tran ID | Tran Serial | Description | Cr/Dr | Amount | Balance */
        foreach ($matrix as $ri => $row) {
            $c0 = trim((string)($row[0] ?? ''));
            if (stripos($c0,'Account Number') !== false && preg_match('/([0-9]{5,})/', $c0, $m)) {
                $meta['account_number'] = $m[1];
            }
            if ($ri >= 5) break;
        }
        foreach ($matrix as $ri => $row) {
            if (trim((string)($row[0] ?? '')) === 'Txn Date') { $header_row = $ri; break; }
        }
        if ($header_row === null) return ['error' => 'Could not find "Txn Date" header row. Check the Sampath file format.'];

        foreach ($matrix as $ri => $row) {
            if ($ri <= $header_row) continue;
            $td = safeDate($row[0] ?? '');
            if (!$td) { $skipped++; continue; }
            $crdr   = strtoupper(trim((string)($row[4] ?? '')));
            $amount = safeAmount($row[5] ?? '');
            if ($amount === null) { $skipped++; continue; }

            $rows[] = [
                'td'     => $td,
                'vd'     => null,
                'desc'   => safeStr($row[3] ?? '', 500),
                'debit'  => ($crdr === 'DR') ? $amount : null,
                'credit' => ($crdr === 'CR') ? $amount : null,
                'bal'    => safeAmount($row[6] ?? ''),
                'br'     => null,
                'ch'     => null,
                'sr'     => safeStr($row[2] ?? '', 50),
                'ref'    => safeStr($row[1] ?? '', 50),
            ];
        }
    }

    return ['meta' => $meta, 'rows' => $rows, 'skipped' => $skipped];
}

/* ══════════════════════════════════════════════════════════
   DUPLICATE CHECK
   A row is a duplicate when every field matches EXCEPT the
   Serial No: txn date, value date, description, debit, credit,
   balance, branch, cheque no, reference — within the same
   bank account. Also flags repeated rows inside the same file.
══════════════════════════════════════════════════════════ */
function txnDupKey($r) {
    $n = function($v) { return ($v === null || $v === '') ? '' : number_format((float)$v, 2, '.', ''); };
    $s = function($v) { return strtoupper(trim(preg_replace('/\s+/', ' ', (string)$v))); };
    $ch = $s($r['ch'] ?? '');
    if ($ch === '0') $ch = '';
    return implode('|', [
        (string)($r['td'] ?? ''), (string)($r['vd'] ?? ''), $s($r['desc'] ?? ''),
        $n($r['debit'] ?? null), $n($r['credit'] ?? null), $n($r['bal'] ?? null),
        $s($r['br'] ?? ''), $ch, $s($r['ref'] ?? ''),
    ]);
}

function findDuplicates($conn, $account_id, $rows) {
    $dups = [];
    if (!$rows) return $dups;

    $dates = array_column($rows, 'td');
    $min = mysqli_real_escape_string($conn, min($dates));
    $max = mysqli_real_escape_string($conn, max($dates));

    // Existing transactions for this account in the file's date range
    $existing = [];   // key => [info, info, …]  (one entry per stored row)
    $q = mysqli_query($conn, "
        SELECT t.transaction_date, t.value_date, t.description, t.debit, t.credit, t.balance,
               t.branch_code, t.cheque_no, t.reference, t.serial_no,
               u.id AS upload_id, u.statement_date, u.original_filename
        FROM bank_statement_transactions t
        JOIN bank_statement_uploads u ON u.id = t.upload_id
        WHERE u.account_id = " . (int)$account_id . "
          AND t.transaction_date BETWEEN '$min' AND '$max'
    ");
    while ($q && ($e = mysqli_fetch_assoc($q))) {
        $key = txnDupKey([
            'td' => $e['transaction_date'], 'vd' => $e['value_date'], 'desc' => $e['description'],
            'debit' => $e['debit'], 'credit' => $e['credit'], 'bal' => $e['balance'],
            'br' => $e['branch_code'], 'ch' => $e['cheque_no'], 'ref' => $e['reference'],
        ]);
        $existing[$key][] = 'Already imported in upload #' . $e['upload_id']
            . ' (statement ' . date('d M Y', strtotime($e['statement_date'])) . ', '
            . $e['original_filename'] . ')'
            . ($e['serial_no'] !== null && $e['serial_no'] !== '' ? ' · serial ' . $e['serial_no'] : '');
    }

    $seen_in_file = [];
    foreach ($rows as $i => $r) {
        $key = txnDupKey($r);
        if (!empty($existing[$key])) {
            $dups[$i] = ['kind' => 'db', 'where' => array_shift($existing[$key])];
        } elseif (isset($seen_in_file[$key])) {
            $dups[$i] = ['kind' => 'file', 'where' => 'Same as row #' . ($seen_in_file[$key] + 1) . ' in this file'];
        } else {
            $seen_in_file[$key] = $i;
        }
    }
    return $dups;
}

/* ══════════════════════════════════════════════════════════
   COMMIT ROWS TO DB
══════════════════════════════════════════════════════════ */
function commitImport($conn, $p, $rows, $skipped_parse, $skipped_dups) {
    if (!$rows) {
        return ['ok' => false, 'msg' => 'Nothing imported — no rows left to import' . ($skipped_dups ? " ($skipped_dups duplicate rows skipped)" : '') . '.'];
    }

    $meta = $p['meta'];
    $sql = sprintf("INSERT INTO bank_statement_uploads
            (account_id,statement_date,customer_id,customer_name,account_number,original_filename,total_rows,bank_type)
            VALUES (%d,%s,%s,%s,%s,%s,0,%s)",
        (int)$p['account_id'],
        dbEsc($conn, $p['statement_date']),
        dbEsc($conn, (string)$meta['customer_id']),
        dbEsc($conn, (string)$meta['customer_name']),
        dbEsc($conn, (string)$meta['account_number']),
        dbEsc($conn, $p['filename']),
        dbEsc($conn, $p['bank_type'])
    );
    mysqli_query($conn, $sql);
    $upload_id = mysqli_insert_id($conn);
    if (!$upload_id) return ['ok' => false, 'msg' => 'Failed to create upload record.'];

    $num = function($v) { return $v === null ? 'NULL' : (string)(float)$v; };
    $inserted = 0;
    $batch = [];
    $flush = function() use (&$batch, &$inserted, $conn) {
        if (!$batch) return;
        mysqli_query($conn, "INSERT INTO bank_statement_transactions
            (upload_id, transaction_date, value_date, description, debit, credit, balance, branch_code, cheque_no, serial_no, reference)
            VALUES " . implode(',', $batch));
        $inserted += count($batch);
        $batch = [];
    };

    foreach ($rows as $r) {
        $batch[] = sprintf("(%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)",
            $upload_id,
            dbEsc($conn, $r['td']), dbEsc($conn, $r['vd']), dbEsc($conn, $r['desc']),
            $num($r['debit']), $num($r['credit']), $num($r['bal']),
            dbEsc($conn, $r['br']), dbEsc($conn, $r['ch']), dbEsc($conn, $r['sr']), dbEsc($conn, $r['ref'])
        );
        if (count($batch) >= 200) $flush();
    }
    $flush();

    mysqli_query($conn, "UPDATE bank_statement_uploads SET total_rows=$inserted WHERE id=$upload_id");

    if ($inserted === 0) {
        mysqli_query($conn, "DELETE FROM bank_statement_uploads WHERE id=$upload_id");
        return ['ok' => false, 'msg' => "No transactions found. ($skipped_parse rows skipped) Check file format."];
    }

    $bank_label = ['BOC'=>'BOC','NDB'=>'NDB','SAMPATH'=>'Sampath'][$p['bank_type']] ?? $p['bank_type'];
    $label = "$bank_label | " . ($meta['customer_name'] ?: '—') . " | A/C: " . $meta['account_number'];
    $extra = [];
    if ($skipped_parse) $extra[] = "$skipped_parse non-data rows skipped";
    if ($skipped_dups)  $extra[] = "$skipped_dups duplicates skipped";
    return ['ok' => true, 'msg' => "Successfully imported $inserted transactions" . ($extra ? ' (' . implode(', ', $extra) . ')' : '') . ". $label"];
}

/* ── Pending-import temp storage (between preview and confirm) ── */
function pendingDir() {
    $d = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'bsu_pending';
    if (!is_dir($d)) @mkdir($d, 0700, true);
    // clean files older than 2 hours
    foreach ((array)glob($d . DIRECTORY_SEPARATOR . '*.json') as $f) {
        if (is_file($f) && filemtime($f) < time() - 7200) @unlink($f);
    }
    return $d;
}
function pendingPath($token) {
    if (!preg_match('/^[a-f0-9]{32}$/', (string)$token)) return null;
    return pendingDir() . DIRECTORY_SEPARATOR . $token . '.json';
}


/* ══════════════════════════════════════════════════════════
   HANDLE DELETE
══════════════════════════════════════════════════════════ */
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $del_id = intval($_GET['delete']);
    mysqli_query($conn, "DELETE FROM bank_statement_transactions WHERE upload_id = $del_id");
    mysqli_query($conn, "DELETE FROM bank_statement_uploads WHERE id = $del_id");
    header('Location: bank_statements.php?deleted=1');
    exit;
}


/* ══════════════════════════════════════════════════════════
   AJAX: STEP 1 — UPLOAD + PARSE + DUPLICATE CHECK
   (imports straight away when there are no duplicates)
══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    header('Content-Type: application/json');

    $account_id     = intval($_POST['account_id'] ?? 0);
    $statement_date = trim($_POST['statement_date'] ?? '');
    $bank_type      = strtoupper(trim($_POST['bank_type'] ?? 'BOC'));
    if (!in_array($bank_type, ['BOC','NDB','SAMPATH'])) $bank_type = 'BOC';

    if (!$account_id) { echo json_encode(['ok'=>false,'msg'=>'Please select a bank account.']); exit; }
    if (!$statement_date || !strtotime($statement_date)) { echo json_encode(['ok'=>false,'msg'=>'Please select a valid statement date.']); exit; }
    if (!isset($_FILES['statement_file']) || $_FILES['statement_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok'=>false,'msg'=>'File upload failed. Please try again.']); exit;
    }

    $file = $_FILES['statement_file'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if ($bank_type === 'NDB')          $allowed = ['csv'];
    elseif ($bank_type === 'SAMPATH')  $allowed = ['xls','html','htm'];
    else                                $allowed = ['xls','xlsx','csv'];

    if (!in_array($ext, $allowed)) {
        $types = implode(', ', array_map('strtoupper', $allowed));
        echo json_encode(['ok'=>false,'msg'=>"For $bank_type statements only $types files are allowed."]); exit;
    }
    if ($file['size'] > 10 * 1024 * 1024) { echo json_encode(['ok'=>false,'msg'=>'File too large. Maximum size is 10 MB.']); exit; }

    /* Selected account's own account number (preferred over the one in the file) */
    $selected_account_no = '';
    $acc_lookup = mysqli_query($conn, "SELECT account_no FROM company_bank_accounts WHERE id=" . intval($account_id));
    if ($acc_lookup && ($acc_row = mysqli_fetch_assoc($acc_lookup))) {
        $selected_account_no = trim((string)($acc_row['account_no'] ?? ''));
    }

    $matrix = parseToMatrix($file['tmp_name'], $ext, $bank_type);
    if (isset($matrix['error'])) { echo json_encode(['ok'=>false,'msg'=>'Parse error: ' . $matrix['error']]); exit; }

    $parsed = matrixToRows($matrix, $bank_type);
    if (isset($parsed['error'])) { echo json_encode(['ok'=>false,'msg'=>$parsed['error']]); exit; }

    $rows = $parsed['rows'];
    if (!$rows) { echo json_encode(['ok'=>false,'msg'=>"No transactions found. ({$parsed['skipped']} rows skipped) Check file format."]); exit; }

    $meta = $parsed['meta'];
    if ($selected_account_no !== '') $meta['account_number'] = $selected_account_no;

    $payload = [
        'account_id'     => $account_id,
        'statement_date' => $statement_date,
        'bank_type'      => $bank_type,
        'filename'       => $file['name'],
        'meta'           => $meta,
        'rows'           => $rows,
        'skipped'        => $parsed['skipped'],
    ];

    $dups = findDuplicates($conn, $account_id, $rows);

    /* No duplicates → import right away */
    if (!$dups) {
        echo json_encode(commitImport($conn, $payload, $rows, $parsed['skipped'], 0));
        exit;
    }

    /* Duplicates → keep parsed rows on the server and ask the user */
    $payload['dup_idx'] = array_keys($dups);
    $token = bin2hex(random_bytes(16));
    file_put_contents(pendingPath($token), json_encode($payload));

    $list = [];
    foreach ($dups as $i => $d) {
        $r = $rows[$i];
        $list[] = [
            'idx'    => $i,
            'row_no' => $i + 1,
            'kind'   => $d['kind'],
            'where'  => $d['where'],
            'td'     => $r['td'] ? date('d M Y', strtotime($r['td'])) : '',
            'desc'   => (string)$r['desc'],
            'debit'  => $r['debit']  !== null ? number_format($r['debit'], 2)  : '',
            'credit' => $r['credit'] !== null ? number_format($r['credit'], 2) : '',
            'bal'    => $r['bal']    !== null ? number_format($r['bal'], 2)    : '',
            'serial' => (string)($r['sr'] ?? ''),
            'ref'    => (string)($r['ref'] ?? ''),
        ];
    }

    echo json_encode([
        'ok'           => true,
        'need_confirm' => true,
        'token'        => $token,
        'total'        => count($rows),
        'new_count'    => count($rows) - count($dups),
        'dup_count'    => count($dups),
        'dups'         => $list,
    ]);
    exit;
}


/* ══════════════════════════════════════════════════════════
   AJAX: STEP 2 — CONFIRM IMPORT (user chose which duplicates)
══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_import') {
    header('Content-Type: application/json');

    $path = pendingPath($_POST['token'] ?? '');
    if (!$path || !is_file($path)) { echo json_encode(['ok'=>false,'msg'=>'This import has expired. Please upload the file again.']); exit; }

    $payload = json_decode(file_get_contents($path), true);
    @unlink($path);
    if (!is_array($payload) || empty($payload['rows'])) { echo json_encode(['ok'=>false,'msg'=>'Import data is invalid. Please upload the file again.']); exit; }

    $dup_set = array_flip(array_map('intval', $payload['dup_idx'] ?? []));
    $chosen  = array_flip(array_map('intval', (array)($_POST['import_dups'] ?? [])));

    $final = [];
    $skipped_dups = 0;
    foreach ($payload['rows'] as $i => $r) {
        if (isset($dup_set[$i]) && !isset($chosen[$i])) { $skipped_dups++; continue; }
        $final[] = $r;
    }

    echo json_encode(commitImport($conn, $payload, $final, (int)($payload['skipped'] ?? 0), $skipped_dups));
    exit;
}


/* ══════════════════════════════════════════════════════════
   AJAX: CANCEL PENDING IMPORT
══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_import') {
    header('Content-Type: application/json');
    $path = pendingPath($_POST['token'] ?? '');
    if ($path && is_file($path)) @unlink($path);
    echo json_encode(['ok' => true, 'msg' => 'Import cancelled. Nothing was saved.']);
    exit;
}


/* ══════════════════════════════════════════════════════════
   VIEW SPECIFIC UPLOAD
══════════════════════════════════════════════════════════ */
$view_upload  = null;
$transactions = [];
if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    $view_id = intval($_GET['view']);
    $vr = mysqli_query($conn,"
        SELECT bsu.*, cba.account_name, cba.account_no AS cba_no, cba.account_type,
               b.bank_name, c.company_name, c.company_code
        FROM bank_statement_uploads bsu
        LEFT JOIN company_bank_accounts cba ON bsu.account_id=cba.id
        LEFT JOIN banks b ON cba.bank_code=b.bank_code
        LEFT JOIN companies c ON cba.company_id=c.id
        WHERE bsu.id=$view_id
    ");
    $view_upload = mysqli_fetch_assoc($vr);
    $tr = mysqli_query($conn,"SELECT * FROM bank_statement_transactions WHERE upload_id=$view_id ORDER BY id ASC");
    while ($t = mysqli_fetch_assoc($tr)) $transactions[] = $t;

    /* batch of each Collection Reconcile line (link to the batch) */
    $recon_batch = [];
    $rids = [];
    foreach ($transactions as $t) if (($t['recon_source'] ?? '') === 'cc_dp_deposit' && !empty($t['recon_ref_id'])) $rids[] = (int)$t['recon_ref_id'];
    if ($rids) {
        try {
            $rb = mysqli_query($conn, "SELECT r.id, b.id AS bid, b.batch_no FROM cc_dp_bank_recon r
                                        LEFT JOIN cc_dp_bank_recon_batches b ON b.id = r.batch_id
                                        WHERE r.id IN (" . implode(',', array_unique($rids)) . ")");
            while ($rb && ($row = mysqli_fetch_assoc($rb))) $recon_batch[(int)$row['id']] = $row;
        } catch (Throwable $e) {}
    }
}


/* ══════════════════════════════════════════════════════════
   PAGE DATA
══════════════════════════════════════════════════════════ */
$accs_q = mysqli_query($conn,"
    SELECT cba.id, cba.account_no, cba.account_name, cba.account_type,
           c.company_name, c.company_code, b.bank_name
    FROM company_bank_accounts cba
    LEFT JOIN companies c ON cba.company_id=c.id
    LEFT JOIN banks b ON cba.bank_code=b.bank_code
    WHERE cba.active=1
    ORDER BY c.company_name, cba.account_name ASC
");
$accounts_arr = [];
while ($a = mysqli_fetch_assoc($accs_q)) $accounts_arr[] = $a;

$stats = mysqli_fetch_assoc(mysqli_query($conn,"
    SELECT COUNT(*) AS total_uploads, COALESCE(SUM(total_rows),0) AS total_rows,
           COUNT(DISTINCT statement_date) AS unique_dates, MAX(uploaded_at) AS last_upload
    FROM bank_statement_uploads
")) ?: ['total_uploads'=>0,'total_rows'=>0,'unique_dates'=>0,'last_upload'=>null];

$has_recon_col = false;
try {
    $cc = mysqli_query($conn, "SHOW COLUMNS FROM bank_statement_transactions LIKE 'recon_status'");
    $has_recon_col = $cc && mysqli_num_rows($cc) > 0;
} catch (Throwable $e) {}

$upload_rc = [];
$rc_totals = ['n' => 0, 'rc' => 0];
try {
    $rc_expr = $has_recon_col ? "SUM(CASE WHEN recon_status IS NOT NULL AND recon_status <> '' THEN 1 ELSE 0 END)" : "0";
    $rq = mysqli_query($conn, "SELECT upload_id, COUNT(*) AS n, $rc_expr AS rc
                               FROM bank_statement_transactions GROUP BY upload_id");
    while ($rq && ($x = mysqli_fetch_assoc($rq))) {
        $upload_rc[(int)$x['upload_id']] = ['n' => (int)$x['n'], 'rc' => (int)$x['rc']];
        $rc_totals['n']  += (int)$x['n'];
        $rc_totals['rc'] += (int)$x['rc'];
    }
} catch (Throwable $e) {}

function rcRate($rc, $n) { return $n > 0 ? round($rc / $n * 100, 1) : 0; }
function rcRateClass($pct) { return $pct >= 100 ? 'full' : ($pct >= 75 ? 'good' : ($pct >= 40 ? 'mid' : 'low')); }

include 'header.php';
?>

<!-- ══════════════════════════════════════════════════════════
     PAGE HEADER
══════════════════════════════════════════════════════════ -->
<div class="ph-row">
    <div>
        <?php if ($view_upload): ?>
        <a href="bank_statements.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Statements</a>
        <h2 class="page-title" style="margin-top:6px;">Statement Transactions</h2>
        <p class="page-subtitle">
            <?php
            $bt_label = strtoupper($view_upload['bank_type'] ?? 'BOC');
            $bt_color = bankTypeColor($bt_label);
            ?>
            <span class="badge" style="background:<?= $bt_color ?>;color:#fff;font-size:10px;padding:2px 8px;border-radius:20px;margin-right:6px;"><?= $bt_label ?></span>
            <?= htmlspecialchars($view_upload['customer_name'] ?: $view_upload['company_name'] ?: '—') ?>
            &nbsp;·&nbsp; A/C: <?= htmlspecialchars($view_upload['cba_no'] ?: $view_upload['account_number'] ?: '—') ?>
            &nbsp;·&nbsp; <?= htmlspecialchars($view_upload['bank_name'] ?? '') ?>
        </p>
        <?php else: ?>
        <h2 class="page-title"><i class="fa-solid fa-landmark" style="color:#1e40af;margin-right:8px;"></i>Bank Statements</h2>
        <p class="page-subtitle">Upload and manage company bank statements</p>
        <?php endif; ?>
    </div>
    <div>
        <a href="bank_account_dashboard.php?totals=1<?= $view_upload ? '&account=' . (int)$view_upload['account_id'] : '' ?>" class="btn btn-primary btn-sm" title="Date-range totals statement per bank account">
            <i class="fa-solid fa-calculator"></i> Totals statement
        </a>
        <a href="bank_datewise_transactions.php<?= $view_upload ? '?account=' . (int)$view_upload['account_id'] . '&from=' . urlencode($view_upload['statement_date']) . '&to=' . urlencode($view_upload['statement_date']) : '' ?>" class="btn btn-primary btn-sm" style="background:#0f766e;" title="Transactions by date with reconciled status">
            <i class="fa-solid fa-list-check"></i> Date-wise transactions
        </a>
    </div>
</div>

<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> Statement deleted successfully.</div>
<?php endif; ?>


<?php if ($view_upload): ?>
<!-- ══════ TRANSACTION VIEW ══════ -->
<?php
$total_debit  = array_sum(array_column($transactions,'debit'));
$total_credit = array_sum(array_column($transactions,'credit'));
$last_balance = !empty($transactions) ? end($transactions)['balance'] : 0;
$view_bank_type   = strtoupper($view_upload['bank_type'] ?? 'BOC');
$is_ndb_view      = $view_bank_type === 'NDB';
$is_sampath_view  = $view_bank_type === 'SAMPATH';
?>
<div class="sum-cards">
    <div class="sum-card">
        <div class="sum-icon icon-credit"><i class="fa-solid fa-arrow-down-to-line"></i></div>
        <div><div class="sum-label">Total Credits</div><div class="sum-val green">LKR <?= number_format($total_credit,2) ?></div></div>
    </div>
    <div class="sum-card">
        <div class="sum-icon icon-debit"><i class="fa-solid fa-arrow-up-from-line"></i></div>
        <div><div class="sum-label">Total Debits</div><div class="sum-val red">LKR <?= number_format($total_debit,2) ?></div></div>
    </div>
    <div class="sum-card">
        <div class="sum-icon icon-balance"><i class="fa-solid fa-scale-balanced"></i></div>
        <div><div class="sum-label">Closing Balance</div><div class="sum-val blue">LKR <?= number_format($last_balance,2) ?></div></div>
    </div>
    <div class="sum-card">
        <div class="sum-icon icon-txn"><i class="fa-solid fa-list-ul"></i></div>
        <div><div class="sum-label">Transactions</div><div class="sum-val"><?= count($transactions) ?></div></div>
    </div>
    <?php
    $rc_n = 0; $rc_amt = 0; $cr_n = 0; $cr_rc = 0; $dr_n = 0; $dr_rc = 0;
    foreach ($transactions as $t) {
        $is_rc = !empty($t['recon_status']);
        if ((float)$t['credit'] > 0) { $cr_n++; if ($is_rc) $cr_rc++; }
        if ((float)$t['debit']  > 0) { $dr_n++; if ($is_rc) $dr_rc++; }
        if ($is_rc) { $rc_n++; $rc_amt += (float)$t['credit'] + (float)$t['debit']; }
    }
    $row_n   = count($transactions);
    $rc_pct  = rcRate($rc_n, $row_n);
    $pend_n  = $row_n - $rc_n;
    ?>
    <div class="sum-card">
        <div class="sum-icon icon-credit"><i class="fa-solid fa-circle-check"></i></div>
        <div><div class="sum-label">Reconciled</div><div class="sum-val green"><?= number_format($rc_n) ?> <span style="font-size:12px;color:#777;font-weight:500;">of <?= number_format($row_n) ?> rows</span></div><div style="font-size:11px;color:#777;margin-top:3px;">LKR <?= number_format($rc_amt,2) ?></div></div>
    </div>
    <div class="sum-card">
        <div class="sum-icon icon-debit"><i class="fa-solid fa-hourglass-half"></i></div>
        <div><div class="sum-label">Not Reconciled</div><div class="sum-val red"><?= number_format($pend_n) ?> <span style="font-size:12px;color:#777;font-weight:500;">rows</span></div></div>
    </div>
    <div class="sum-card">
        <div class="sum-icon icon-balance"><i class="fa-solid fa-percent"></i></div>
        <div style="flex:1;min-width:0;">
            <div class="sum-label">Reconciled Rate</div>
            <div class="sum-val"><?= $rc_pct ?>%</div>
            <div class="rate-bar <?= rcRateClass($rc_pct) ?>"><span style="width:<?= min(100,$rc_pct) ?>%"></span></div>
            <div style="font-size:11px;color:#777;margin-top:4px;">Credits <?= $cr_rc ?>/<?= $cr_n ?> · Debits <?= $dr_rc ?>/<?= $dr_n ?></div>
        </div>
    </div>
</div>

<div class="table-card">
    <div class="table-toolbar">
        <div class="tbl-title">Transaction Detail</div>
        <div class="rc-filter" role="group" aria-label="Reconcile filter">
            <button type="button" class="on" data-rc="all">All (<?= number_format($row_n) ?>)</button>
            <button type="button" data-rc="yes">Reconciled (<?= number_format($rc_n) ?>)</button>
            <button type="button" data-rc="no">Not reconciled – credits (<?= number_format($cr_n - $cr_rc) ?>)</button>
            <button type="button" data-rc="na">Not reconciled – debits (<?= number_format($row_n - $rc_n - ($cr_n - $cr_rc)) ?>)</button>
        </div>
        <span class="meta-info">
            Statement: <strong><?= date('d M Y',strtotime($view_upload['statement_date'])) ?></strong>
            &nbsp;|&nbsp; Uploaded: <strong><?= date('d M Y H:i',strtotime($view_upload['uploaded_at'])) ?></strong>
        </span>
    </div>
    <div class="dt-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>Txn Date</th>
                <th>Value Date</th>
                <th>Description</th>
                <?php if ($is_ndb_view): ?>
                <th>Reference</th>
                <?php elseif ($is_sampath_view): ?>
                <th>Reference</th><th>Serial</th>
                <?php else: ?>
                <th>Branch</th><th>Cheque</th><th>Serial</th>
                <?php endif; ?>
                <th class="tr">Debit</th>
                <th class="tr">Credit</th>
                <th class="tr">Balance</th>
                <th>Recon Status</th>
                <th>Recon Category</th>
                <th>Recon Remark</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($transactions as $t):
            $rc_on = !empty($t['recon_status']); ?>
            <tr data-rc="<?= $rc_on ? 'yes' : ((float)$t['credit'] > 0 ? 'no' : 'na') ?>">
                <td><?= $t['transaction_date']?date('d M Y',strtotime($t['transaction_date'])):'—' ?></td>
                <td><?= $t['value_date']?date('d M Y',strtotime($t['value_date'])):'—' ?></td>
                <td style="max-width:260px;font-size:12px;"><?= htmlspecialchars(trim($t['description']??'')) ?></td>
                <?php if ($is_ndb_view): ?>
                <td style="font-size:11px;color:#555;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($t['reference']??'') ?>">
                    <?= htmlspecialchars(mb_strimwidth($t['reference']??'', 0, 30, '…')) ?>
                </td>
                <?php elseif ($is_sampath_view): ?>
                <td style="font-size:11px;color:#555;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($t['reference']??'') ?>">
                    <?= htmlspecialchars($t['reference']??'—') ?>
                </td>
                <td><span class="badge badge-blue"><?= htmlspecialchars($t['serial_no']??'') ?></span></td>
                <?php else: ?>
                <td><code style="background:#f5f5f5;padding:2px 6px;border-radius:4px;font-size:11px;"><?= htmlspecialchars($t['branch_code']??'') ?></code></td>
                <td class="muted"><?= ($t['cheque_no']&&$t['cheque_no']!=='0')?htmlspecialchars($t['cheque_no']):'—' ?></td>
                <td><span class="badge badge-blue"><?= htmlspecialchars($t['serial_no']??'') ?></span></td>
                <?php endif; ?>
                <td class="tr"><?= $t['debit']>0?'<span class="amt debit">'.number_format($t['debit'],2).'</span>':'<span class="muted">—</span>' ?></td>
                <td class="tr"><?= $t['credit']>0?'<span class="amt credit">'.number_format($t['credit'],2).'</span>':'<span class="muted">—</span>' ?></td>
                <td class="tr">
                    <?php
                    $bal_val = (float)$t['balance'];
                    $bal_cls = $bal_val < 0 ? 'debit' : 'balance';
                    ?>
                    <span class="amt <?= $bal_cls ?>"><?= number_format($bal_val,2) ?></span>
                </td>
                <td>
                    <?php if ($rc_on): ?>
                        <?php if ($t['recon_status'] === 'partial'): ?>
                        <span class="rc-badge rc-diff"><i class="fa-solid fa-circle-half-stroke"></i> Partially reconciled</span>
                        <?php else: ?>
                        <span class="rc-badge <?= $t['recon_status']==='reconciled' ? 'rc-ok' : 'rc-diff' ?>">
                            <i class="fa-solid fa-circle-check"></i> <?= $t['recon_status']==='reconciled' ? 'Reconciled' : 'Reconciled (difference)' ?>
                        </span>
                        <?php endif; ?>
                        <?php if (!empty($t['recon_at'])): ?><div class="rc-sub"><?= date('d M Y H:i', strtotime($t['recon_at'])) ?><?= !empty($t['recon_by']) ? ' · ' . htmlspecialchars($t['recon_by']) : '' ?></div><?php endif; ?>
                    <?php elseif ((float)$t['credit'] > 0): ?>
                        <span class="rc-badge rc-no">Not reconciled</span>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                    <?php $bmr_b = bmr_button($t); if ($bmr_b !== ''): ?><div><?= $bmr_b ?></div><?php endif; ?>
                </td>
                <td>
                    <?php
                    $rc_cat = $t['recon_category'] ?? '';
                    if ($rc_on && $rc_cat === '' && ($t['recon_source'] ?? '') === 'cc_dp_deposit') $rc_cat = 'Collection Reconcile';
                    if ($rc_on && $rc_cat === '' && ($t['recon_source'] ?? '') === 'cheque_recon')  $rc_cat = 'Cheque Reconcile';
                    if ($rc_on && $rc_cat === '' && ($t['recon_source'] ?? '') === 'unilever_recon') $rc_cat = 'Claim Reconcile';
                    if ($rc_on && $rc_cat === '' && ($t['recon_source'] ?? '') === 'pi_cheque_recon') $rc_cat = 'Payment Cheque Reconcile';
                    if ($rc_on && $rc_cat === '' && ($t['recon_source'] ?? '') === 'stl_grant_recon') $rc_cat = 'STL Loan Reconcile';
                    if ($rc_on && $rc_cat === '' && ($t['recon_source'] ?? '') === 'stl_settle_bank') $rc_cat = 'STL Loan Settlement';
                    if ($rc_on && $rc_cat === '' && ($t['recon_source'] ?? '') === 'ca_issued_chq_recon') $rc_cat = 'Issued Cheque Reconcile';
                    if ($rc_on && $rc_cat === '' && ($t['recon_source'] ?? '') === 'expense_pay_recon') $rc_cat = 'Expense Payment Reconcile';
                    if ($rc_on && $rc_cat === '' && ($t['recon_source'] ?? '') === 'manual_recon') $rc_cat = 'Manual Reconcile';
                    ?>
                    <?php if ($rc_on && $rc_cat !== ''): ?>
                        <span class="rc-cat"><?= htmlspecialchars($rc_cat) ?></span>
                        <?php $rb_row = $recon_batch[(int)($t['recon_ref_id'] ?? 0)] ?? null; ?>
                        <?php if ($rb_row && $rb_row['bid']): ?>
                            <div class="rc-sub"><a href="cc_deposit_dp_bank_recon.php?tab=saved&batch=<?= (int)$rb_row['bid'] ?>"><?= htmlspecialchars($rb_row['batch_no']) ?></a> · Recon #<?= (int)$t['recon_ref_id'] ?></div>
                        <?php endif; ?>
                        <?php if (($t['recon_source'] ?? '') === 'cheque_recon' && !empty($t['recon_ref_id'])): ?>
                            <div class="rc-sub"><a href="cheque_recon_history.php">Cheque Recon #<?= (int)$t['recon_ref_id'] ?></a></div>
                        <?php endif; ?>
                        <?php if (($t['recon_source'] ?? '') === 'unilever_recon'): ?>
                            <div class="rc-sub"><a href="unilever_reconcile.php">Unilever Claim Recon</a></div>
                        <?php endif; ?>
                        <?php if (($t['recon_source'] ?? '') === 'expense_pay_recon'): ?>
                            <div class="rc-sub"><a href="expense_payment_bank_recon.php?tab=saved">Expense Payment Recon #<?= (int)$t['recon_ref_id'] ?></a></div>
                        <?php endif; ?>
                        <?php if (($t['recon_source'] ?? '') === 'ca_issued_chq_recon'): ?>
                            <div class="rc-sub"><a href="ca_issued_cheque_bank_recon.php?tab=saved">Issued Cheque Recon #<?= (int)$t['recon_ref_id'] ?></a></div>
                        <?php endif; ?>
                        <?php if (($t['recon_source'] ?? '') === 'stl_settle_bank'): ?>
                            <div class="rc-sub"><a href="stl_loan_settle_bank.php?tab=history">STL Settle with Bank</a></div>
                        <?php endif; ?>
                        <?php if (($t['recon_source'] ?? '') === 'stl_grant_recon'): ?>
                            <div class="rc-sub"><a href="stl_loan_grant_recon.php?tab=saved">STL Loan Recon #<?= (int)$t['recon_ref_id'] ?></a></div>
                        <?php endif; ?>
                        <?php if (($t['recon_source'] ?? '') === 'manual_recon'): ?>
                            <div class="rc-sub"><a href="bank_recon_reasons.php">Manual Recon #<?= (int)$t['recon_ref_id'] ?></a></div>
                        <?php endif; ?>
                        <?php if (($t['recon_source'] ?? '') === 'pi_cheque_recon'): ?>
                            <div class="rc-sub"><a href="pi_cheque_bank_recon.php?tab=saved">Payment Cheque Recon #<?= (int)$t['recon_ref_id'] ?></a></div>
                        <?php endif; ?>
                    <?php else: ?><span class="muted">—</span><?php endif; ?>
                </td>
                <td class="rc-remark"><?= $rc_on && !empty($t['recon_remark']) ? htmlspecialchars($t['recon_remark']) : '<span class="muted">—</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>


<?php else: ?>
<!-- ══════ UPLOAD + LIST ══════ -->

<div class="sum-cards">
    <div class="sum-card">
        <div class="sum-icon icon-txn"><i class="fa-solid fa-upload"></i></div>
        <div><div class="sum-label">Total Uploads</div><div class="sum-val"><?= number_format($stats['total_uploads']) ?></div></div>
    </div>
    <div class="sum-card">
        <div class="sum-icon icon-balance"><i class="fa-solid fa-list-ol"></i></div>
        <div><div class="sum-label">Total Transactions</div><div class="sum-val"><?= number_format($stats['total_rows']) ?></div></div>
    </div>
    <?php $all_pct = rcRate($rc_totals['rc'], $rc_totals['n']); ?>
    <div class="sum-card">
        <div class="sum-icon icon-credit"><i class="fa-solid fa-circle-check"></i></div>
        <div style="flex:1;min-width:0;">
            <div class="sum-label">Reconciled Rows</div>
            <div class="sum-val green"><?= number_format($rc_totals['rc']) ?> <span style="font-size:12px;color:#777;font-weight:500;">of <?= number_format($rc_totals['n']) ?> · <?= $all_pct ?>%</span></div>
            <div class="rate-bar <?= rcRateClass($all_pct) ?>"><span style="width:<?= min(100,$all_pct) ?>%"></span></div>
        </div>
    </div>
    <div class="sum-card">
        <div class="sum-icon icon-credit"><i class="fa-solid fa-calendar-days"></i></div>
        <div><div class="sum-label">Statement Dates</div><div class="sum-val"><?= number_format($stats['unique_dates']) ?></div></div>
    </div>
    <div class="sum-card">
        <div class="sum-icon icon-debit"><i class="fa-solid fa-clock"></i></div>
        <div><div class="sum-label">Last Upload</div><div class="sum-val" style="font-size:13px;padding-top:4px;"><?= $stats['last_upload']?date('d M Y H:i',strtotime($stats['last_upload'])):'—' ?></div></div>
    </div>
</div>

<!-- UPLOAD CARD -->
<div class="upload-card">
    <div class="upload-card-hdr">
        <h3><i class="fa-solid fa-file-arrow-up" style="margin-right:8px;"></i>Upload New Bank Statement</h3>
        <p>Select the bank type, account, statement date, and your file. Rows already imported for the same account are detected before saving.</p>
    </div>
    <div class="upload-card-body">

        <div class="form-group">
            <label>Bank / Statement Type <span style="color:#dc2626">*</span></label>
            <div class="bank-type-toggle">
                <button type="button" class="bt-btn bt-boc active" id="btnBOC" onclick="setBankType('BOC')">
                    <i class="fa-solid fa-university"></i>
                    <span class="bt-name">BOC</span>
                    <span class="bt-sub">Bank of Ceylon</span>
                </button>
                <button type="button" class="bt-btn bt-ndb" id="btnNDB" onclick="setBankType('NDB')">
                    <i class="fa-solid fa-building-columns"></i>
                    <span class="bt-name">NDB</span>
                    <span class="bt-sub">National Development Bank</span>
                </button>
                <button type="button" class="bt-btn bt-sampath" id="btnSAMPATH" onclick="setBankType('SAMPATH')">
                    <i class="fa-solid fa-building-columns"></i>
                    <span class="bt-name">Sampath</span>
                    <span class="bt-sub">Sampath Bank</span>
                </button>
            </div>
            <input type="hidden" id="bankType" value="BOC">
        </div>

        <div class="form-group">
            <label>Bank Account <span style="color:#dc2626">*</span></label>
            <select id="accountId" class="form-control">
                <option value="">-- Select Bank Account --</option>
                <?php foreach ($accounts_arr as $a): ?>
                <option value="<?= $a['id'] ?>">
                    [<?= htmlspecialchars($a['company_code']) ?>]
                    <?= htmlspecialchars($a['bank_name'].' — '.$a['account_name'].' ('.$a['account_no'].')') ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Statement Date <span style="color:#dc2626">*</span></label>
            <input type="date" id="statementDate" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
        </div>

        <div class="form-group">
            <label id="fileLabel">Statement File (XLS / XLSX / CSV) <span style="color:#dc2626">*</span></label>
            <div class="drop-zone" id="dropZone" onclick="document.getElementById('fileInput').click()">
                <div id="dzInner">
                    <div style="font-size:36px;color:#9ca3af;margin-bottom:10px;"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                    <div class="dz-text">Drop file here or click to browse</div>
                    <div class="dz-sub" id="dzSubText">Supports: .xls, .xlsx, .csv &nbsp;·&nbsp; Max 10 MB</div>
                </div>
                <div id="dzPreview" style="display:none;" class="dz-preview-inner">
                    <i class="fa-solid fa-file-excel" id="dzIcon" style="font-size:28px;color:#166534;"></i>
                    <div style="flex:1;overflow:hidden;">
                        <div id="dzName" style="font-weight:600;font-size:14px;color:#111;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></div>
                        <div id="dzSize" style="font-size:12px;color:#6b7280;margin-top:3px;"></div>
                    </div>
                    <button type="button" onclick="clearFile(event)" style="background:#f5f5f5;border:1px solid #e5e5e5;border-radius:6px;width:30px;height:30px;cursor:pointer;font-size:13px;">✕</button>
                </div>
            </div>
            <input type="file" id="fileInput" accept=".xls,.xlsx,.csv" style="display:none">
        </div>

        <div class="format-hint" id="hintBOC">
            <i class="fa-solid fa-circle-info" style="color:#d97706;margin-top:1px;flex-shrink:0;"></i>
            <div><strong>BOC format:</strong> Rows 1–11 contain header metadata (Customer Id, Account Number). Row 12 is the column header (<strong>Transaction Date, Value Date, Description, Debit, Credit, Principal Balance, Branch Code, Cheque No, Serial No.</strong>). Data starts from row 13. Supports XLS, XLSX, CSV.</div>
        </div>

        <div class="format-hint" id="hintNDB" style="display:none;border-color:#c4b5fd;background:#f5f3ff;">
            <i class="fa-solid fa-circle-info" style="color:#7c3aed;margin-top:1px;flex-shrink:0;"></i>
            <div><strong>NDB format:</strong> CSV export from NDB online banking. Contains Account Number & Owning Customer in header rows. Column header row has <strong>Transaction Date, Value Date, Description, Reference, Debit Amount (LKR), Credit Amount (LKR), Running Balance (LKR)</strong>. CSV only.</div>
        </div>

        <div class="format-hint" id="hintSAMPATH" style="display:none;border-color:#fca5a5;background:#fef2f2;">
            <i class="fa-solid fa-circle-info" style="color:#b91c1c;margin-top:1px;flex-shrink:0;"></i>
            <div><strong>Sampath format:</strong> "Full Statement" export from Sampath online banking — this is actually an HTML table saved with a <strong>.xls</strong> extension (also accepts .html/.htm). Title row has "Statement of Account Number : ...". Column header row has <strong>Txn Date, Tran ID, Tran Serial, Description, Cr/Dr, Amount, Balance</strong>. A single Amount column plus Cr/Dr flag is split into Debit/Credit automatically.</div>
        </div>

        <div class="format-hint" style="border-color:#bae6fd;background:#f0f9ff;color:#0c4a6e;">
            <i class="fa-solid fa-copy" style="color:#0284c7;margin-top:1px;flex-shrink:0;"></i>
            <div><strong>Duplicate check:</strong> Before saving, each row is compared with rows already imported for the selected account (date, value date, description, debit, credit, balance, branch, cheque, reference — <strong>Serial No is ignored</strong>). If matches are found you can tick which duplicates to import; unticked ones are skipped.</div>
        </div>

        <div class="progress-wrap" id="progressWrap">
            <div class="progress-bar-outer"><div class="progress-bar-inner" id="progressBar"></div></div>
            <div class="progress-label" id="progressLabel">Uploading…</div>
        </div>
        <div class="result-box" id="resultBox"></div>

        <button class="btn btn-success" id="uploadBtn" onclick="doUpload()" style="width:100%;margin-top:8px;height:44px;font-size:14px;">
            <i class="fa-solid fa-database"></i> Upload & Import to Database
        </button>
    </div>
</div>

<!-- DUPLICATE REVIEW MODAL -->
<div class="dup-overlay" id="dupModal" style="display:none;">
    <div class="dup-box">
        <div class="dup-hdr">
            <div>
                <h3><i class="fa-solid fa-triangle-exclamation" style="color:#d97706;margin-right:8px;"></i>Duplicate rows found</h3>
                <p id="dupSummary"></p>
            </div>
            <button type="button" class="dup-x" onclick="cancelDupImport()" title="Cancel">✕</button>
        </div>
        <div class="dup-tools">
            <label class="dup-all"><input type="checkbox" id="dupCheckAll"> Import all duplicates</label>
            <span class="dup-legend"><span class="dup-tag db">Already in DB</span> <span class="dup-tag file">Repeated in file</span></span>
        </div>
        <div class="dup-scroll">
            <table class="data-table dup-table">
                <thead>
                    <tr>
                        <th class="tc" style="width:60px;">Import?</th>
                        <th>Row</th>
                        <th>Txn Date</th>
                        <th>Description</th>
                        <th class="tr">Debit</th>
                        <th class="tr">Credit</th>
                        <th class="tr">Balance</th>
                        <th>Serial / Ref</th>
                        <th>Duplicate of</th>
                    </tr>
                </thead>
                <tbody id="dupBody"></tbody>
            </table>
        </div>
        <div class="dup-foot">
            <div id="dupCountInfo" class="dup-count"></div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" class="btn btn-light" onclick="cancelDupImport()"><i class="fa-solid fa-xmark"></i> Cancel upload</button>
                <button type="button" class="btn btn-success" id="dupConfirmBtn" onclick="confirmDupImport()"><i class="fa-solid fa-database"></i> Import</button>
            </div>
        </div>
    </div>
</div>

<!-- UPLOADS TABLE -->
<div class="table-card">
    <div class="table-toolbar">
        <div class="tbl-title">Uploaded Statements</div>
    </div>
    <div class="dt-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Type</th>
                <th>Customer / Company</th>
                <th>Account Number</th>
                <th>Bank Account</th>
                <th>Statement Date</th>
                <th>File</th>
                <th class="tr">Rows</th>
                <th class="tr">Reconciled</th>
                <th class="tr">Not Rec.</th>
                <th style="min-width:120px;">Rec. Rate</th>
                <th>Uploaded</th>
                <th class="tc">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $uploads_q = mysqli_query($conn,"
            SELECT bsu.*, cba.account_name, cba.account_no AS cba_no,
                   b.bank_name, c.company_name, c.company_code
            FROM bank_statement_uploads bsu
            LEFT JOIN company_bank_accounts cba ON bsu.account_id=cba.id
            LEFT JOIN banks b ON cba.bank_code=b.bank_code
            LEFT JOIN companies c ON cba.company_id=c.id
            ORDER BY bsu.uploaded_at DESC
        ");
        $i=1;
        while ($u=mysqli_fetch_assoc($uploads_q)):
            $bt_up = strtoupper($u['bank_type'] ?? 'BOC');
            $bt_color_up = bankTypeColor($bt_up);
        ?>
        <tr>
            <td style="color:#9ca3af;font-size:11px;"><?= $i++ ?></td>
            <td>
                <span class="badge" style="background:<?= $bt_color_up ?>;color:#fff;font-size:10px;padding:2px 9px;">
                    <?= htmlspecialchars($bt_up) ?>
                </span>
            </td>
            <td>
                <?php if ($u['company_code']): ?><div style="font-size:10px;color:#888;font-weight:700;text-transform:uppercase;"><?= htmlspecialchars($u['company_code']) ?></div><?php endif; ?>
                <div style="font-weight:600;font-size:13px;"><?= htmlspecialchars($u['customer_name']?:$u['company_name']?:'—') ?></div>
                <?php if ($u['customer_id']): ?><div style="font-size:11px;color:#aaa;">ID: <?= htmlspecialchars($u['customer_id']) ?></div><?php endif; ?>
            </td>
            <td><code style="background:#f5f5f5;padding:2px 8px;border-radius:4px;font-size:11px;"><?= htmlspecialchars($u['cba_no']?:$u['account_number']?:'—') ?></code></td>
            <td>
                <div style="font-size:12px;color:#666;"><?= htmlspecialchars($u['bank_name']??'—') ?></div>
                <div style="font-size:13px;"><?= htmlspecialchars($u['account_name']??'—') ?></div>
            </td>
            <td><span class="badge badge-blue"><i class="fa-regular fa-calendar" style="margin-right:4px;"></i><?= date('d M Y',strtotime($u['statement_date'])) ?></span></td>
            <td style="font-size:12px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($u['original_filename']) ?>">
                <i class="fa-solid fa-file-excel" style="color:#166534;margin-right:4px;"></i><?= htmlspecialchars(mb_strimwidth($u['original_filename'],0,22,'…')) ?>
            </td>
            <?php
            $urc   = $upload_rc[(int)$u['id']] ?? ['n' => (int)$u['total_rows'], 'rc' => 0];
            $u_pct = rcRate($urc['rc'], $urc['n']);
            ?>
            <td class="tr"><span class="badge badge-blue"><?= number_format($urc['n']) ?></span></td>
            <td class="tr"><span class="badge badge-green"><?= number_format($urc['rc']) ?></span></td>
            <td class="tr"><?php $upn = $urc['n'] - $urc['rc']; ?><span class="badge <?= $upn > 0 ? 'badge-amber' : 'badge-gray' ?>"><?= number_format($upn) ?></span></td>
            <td>
                <div class="rate-cell">
                    <div class="rate-bar <?= rcRateClass($u_pct) ?>"><span style="width:<?= min(100,$u_pct) ?>%"></span></div>
                    <b><?= $u_pct ?>%</b>
                </div>
            </td>
            <td style="font-size:12px;"><?= date('d M Y',strtotime($u['uploaded_at'])) ?><br><small style="color:#999;"><?= date('H:i',strtotime($u['uploaded_at'])) ?></small></td>
            <td class="tc" style="white-space:nowrap;">
                <a href="?view=<?= $u['id'] ?>" class="btn btn-primary btn-sm" title="View Transactions"><i class="fa-solid fa-eye"></i></a>
                <a href="?delete=<?= $u['id'] ?>" class="btn btn-danger btn-sm" title="Delete"
                   onclick="return confirm('Delete this statement and all its transactions? This cannot be undone.')"><i class="fa-solid fa-trash"></i></a>
            </td>
        </tr>
        <?php endwhile; ?>
        <?php if ($i===1): ?>
        <tr><td colspan="13" style="text-align:center;padding:40px;color:#9ca3af;"><i class="fa-solid fa-file-arrow-up" style="font-size:32px;margin-bottom:12px;display:block;color:#ddd;"></i>No bank statements uploaded yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php endif; ?>

<div id="toast"></div>

<!-- ══════════════════════════════════════════════════════════
     STYLES
══════════════════════════════════════════════════════════ -->
<style>
*{box-sizing:border-box;}
.ph-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:20px;}
.back-link{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#666;text-decoration:none;font-weight:500;}
.back-link:hover{color:#000;}
.page-title{font-size:18px;font-weight:700;color:#111827;margin:0 0 4px;}
.page-subtitle{font-size:12px;color:#6b7280;margin:0;}

.alert{padding:14px 18px;border-radius:8px;margin-bottom:20px;display:flex;align-items:center;gap:10px;font-size:13px;font-weight:500;}
.alert-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}

.sum-cards{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:22px;}
.rc-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:600;white-space:nowrap;}
.rc-ok{background:#dcfce7;color:#15803d;}.rc-diff{background:#fef3c7;color:#92400e;}.rc-no{background:#f3f4f6;color:#6b7280;}
.rc-cat{display:inline-block;padding:3px 9px;border-radius:6px;background:#ede9fe;color:#5b21b6;font-size:11px;font-weight:600;white-space:nowrap;}
.rc-sub{font-size:11px;color:#777;margin-top:3px;white-space:nowrap;}.rc-sub a{color:#0f766e;font-weight:600;text-decoration:none;}
.rc-remark{font-size:11px;color:#444;line-height:1.45;min-width:260px;max-width:380px;white-space:normal;}
.rc-filter{display:inline-flex;border:1px solid #e5e5e5;border-radius:8px;overflow:hidden;}
.rc-filter button{border:0;background:#fff;padding:6px 12px;font:inherit;font-size:12px;font-weight:600;color:#555;cursor:pointer;}
.rc-filter button.on{background:#111;color:#fff;}
.sum-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 20px;flex:1 1 230px;min-width:0;display:flex;align-items:center;gap:14px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.sum-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;}
.icon-credit{background:#f0fdf4;color:#166534;}.icon-debit{background:#fef2f2;color:#dc2626;}
.icon-balance{background:#eff6ff;color:#1d4ed8;}.icon-txn{background:#faf5ff;color:#7c3aed;}
.sum-label{font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;}
.sum-val{font-size:20px;font-weight:700;color:#111827;}
.sum-val.green{color:#166534;}.sum-val.red{color:#dc2626;}.sum-val.blue{color:#1d4ed8;}

.upload-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin-bottom:22px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
.upload-card-hdr{padding:18px 24px;border-bottom:1px solid #f3f4f6;}
.upload-card-hdr h3{margin:0 0 4px;font-size:15px;color:#111827;font-weight:700;}
.upload-card-hdr p{margin:0;font-size:12px;color:#6b7280;}
.upload-card-body{padding:24px;}
.form-group{margin-bottom:18px;}
.form-group label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px;}
.form-control{width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:7px;font-size:13px;font-family:inherit;color:#111827;outline:none;transition:border .15s;background:#fff;}
.form-control:focus{border-color:#1e40af;box-shadow:0 0 0 3px rgba(30,64,175,.1);}

.bank-type-toggle{display:flex;gap:12px;flex-wrap:wrap;}
.bt-btn{display:flex;flex-direction:column;align-items:center;gap:4px;padding:14px 28px;border:2px solid #e5e7eb;border-radius:10px;background:#fff;cursor:pointer;transition:all .18s;font-family:inherit;flex:1;max-width:200px;min-width:140px;}
.bt-btn i{font-size:22px;color:#9ca3af;transition:color .18s;}
.bt-name{font-size:16px;font-weight:800;color:#374151;letter-spacing:.5px;}
.bt-sub{font-size:10px;color:#9ca3af;font-weight:500;}
.bt-btn:hover{border-color:#c4b5fd;background:#faf5ff;}
.bt-btn:hover i{color:#7c3aed;}
.bt-btn.active.bt-boc{border-color:#1e40af;background:#eff6ff;}
.bt-btn.active.bt-boc i,.bt-btn.active.bt-boc .bt-name{color:#1e40af;}
.bt-btn.active.bt-ndb{border-color:#7c3aed;background:#f5f3ff;}
.bt-btn.active.bt-ndb i,.bt-btn.active.bt-ndb .bt-name{color:#7c3aed;}
.bt-btn.active.bt-sampath{border-color:#b91c1c;background:#fef2f2;}
.bt-btn.active.bt-sampath i,.bt-btn.active.bt-sampath .bt-name{color:#b91c1c;}
.bt-btn.active.bt-boc .bt-sub,.bt-btn.active.bt-ndb .bt-sub,.bt-btn.active.bt-sampath .bt-sub{color:#6b7280;}

.drop-zone{border:2px dashed #d1d5db;border-radius:10px;padding:32px;text-align:center;cursor:pointer;transition:all .18s;background:#fafafa;}
.drop-zone:hover,.drop-zone.dragover{border-color:#1e40af;background:#eff6ff;}
.drop-zone.file-chosen{border-color:#15803d;background:#f0fdf4;padding:20px;}
.dz-text{font-size:13px;font-weight:600;color:#374151;margin-bottom:4px;}
.dz-sub{font-size:11px;color:#9ca3af;}
.dz-preview-inner{display:flex;align-items:center;gap:14px;text-align:left;}

.format-hint{display:flex;align-items:flex-start;gap:10px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:13px 16px;margin-bottom:18px;font-size:12px;color:#78350f;line-height:1.6;}

.progress-wrap{display:none;margin:14px 0;}
.progress-bar-outer{background:#e5e7eb;border-radius:20px;height:8px;overflow:hidden;}
.progress-bar-inner{height:8px;background:linear-gradient(90deg,#1e40af,#3b82f6);border-radius:20px;width:0%;transition:width .3s;}
.progress-label{font-size:11px;color:#6b7280;margin-top:5px;}
.result-box{display:none;padding:12px 16px;border-radius:8px;font-size:13px;font-weight:600;margin-top:12px;}
.result-ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.result-err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
.result-warn{background:#fffbeb;color:#92400e;border:1px solid #fde68a;}

.table-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #f3f4f6;flex-wrap:wrap;gap:8px;}
.tbl-title{font-size:14px;font-weight:700;color:#111827;}
.meta-info{font-size:12px;color:#888;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12px;}
.data-table thead{background:#f9fafb;border-bottom:2px solid #e5e7eb;}
.data-table th{padding:10px 12px;text-align:left;color:#374151;font-size:11px;text-transform:uppercase;white-space:nowrap;letter-spacing:.4px;}
.data-table td{padding:10px 12px;border-bottom:1px solid #f3f4f6;color:#111827;vertical-align:middle;}
.data-table tr:hover td{background:#f9fafb;}
.tr{text-align:right!important;}.tc{text-align:center!important;}
.amt{font-weight:600;font-size:13px;white-space:nowrap;}
.amt.debit{color:#dc2626;}.amt.credit{color:#166534;}.amt.balance{color:#1d4ed8;}
.muted{color:#ccc;}
.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600;}
.badge-blue{background:#dbeafe;color:#1e40af;}.badge-green{background:#dcfce7;color:#15803d;}
.badge-amber{background:#fef3c7;color:#92400e;}.badge-gray{background:#f3f4f6;color:#9ca3af;}
.rate-bar{height:6px;border-radius:999px;background:#e5e7eb;overflow:hidden;margin-top:6px;min-width:70px;}
.rate-bar span{display:block;height:100%;border-radius:999px;background:#dc2626;}
.rate-bar.mid span{background:#d97706;}.rate-bar.good span{background:#16a34a;}.rate-bar.full span{background:#15803d;}
.rate-cell{display:flex;align-items:center;gap:8px;white-space:nowrap;}
.rate-cell .rate-bar{flex:1;margin-top:0;}
.rate-cell b{font-size:12px;min-width:44px;text-align:right;}

.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;text-decoration:none;}
.btn:disabled{opacity:.6;cursor:not-allowed;}
.btn-sm{padding:5px 10px;font-size:12px;}
.btn-primary{background:#1e40af;color:#fff;}.btn-primary:hover{background:#1e3a8a;}
.btn-success{background:#15803d;color:#fff;}.btn-success:hover{background:#166534;}
.btn-danger{background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;}
.btn-light{background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;}.btn-light:hover{background:#e5e7eb;}

/* Duplicate review modal */
.dup-overlay{position:fixed;inset:0;background:rgba(17,24,39,.55);z-index:9000;display:flex;align-items:center;justify-content:center;padding:16px;}
.dup-box{background:#fff;border-radius:12px;width:100%;max-width:1100px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 50px rgba(0,0,0,.25);}
.dup-hdr{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:18px 22px;border-bottom:1px solid #f3f4f6;}
.dup-hdr h3{margin:0 0 4px;font-size:16px;font-weight:700;color:#111827;}
.dup-hdr p{margin:0;font-size:12px;color:#6b7280;line-height:1.5;}
.dup-x{background:#f5f5f5;border:1px solid #e5e5e5;border-radius:6px;width:30px;height:30px;cursor:pointer;font-size:13px;flex-shrink:0;}
.dup-tools{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;padding:10px 22px;border-bottom:1px solid #f3f4f6;background:#fafafa;}
.dup-all{font-size:12px;font-weight:600;color:#374151;display:inline-flex;align-items:center;gap:6px;cursor:pointer;}
.dup-scroll{overflow:auto;flex:1;}
.dup-table td{font-size:12px;}
.dup-table tr.dup-on td{background:#f0fdf4;}
.dup-table tr.dup-off td{color:#9ca3af;}
.dup-table input[type=checkbox],.dup-all input{width:16px;height:16px;cursor:pointer;accent-color:#15803d;}
.dup-tag{display:inline-block;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;white-space:nowrap;}
.dup-tag.db{background:#fee2e2;color:#991b1b;}.dup-tag.file{background:#fef3c7;color:#92400e;}
.dup-where{font-size:11px;color:#6b7280;margin-top:3px;max-width:280px;white-space:normal;}
.dup-legend{font-size:11px;color:#6b7280;}
.dup-foot{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;padding:14px 22px;border-top:1px solid #f3f4f6;}
.dup-count{font-size:12px;color:#374151;}

#toast{position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;display:none;opacity:0;z-index:9999;transition:opacity .3s;max-width:400px;box-shadow:0 4px 20px rgba(0,0,0,.15);}
.toast-ok{background:#166534;color:#fff;}.toast-err{background:#991b1b;color:#fff;}

@media(max-width:600px){
    .sum-cards{flex-direction:column;}
    .upload-card-body{padding:16px;}
    .bank-type-toggle{flex-direction:column;}
    .bt-btn{max-width:100%;flex-direction:row;justify-content:center;}
    .dup-hdr,.dup-tools,.dup-foot{padding-left:14px;padding-right:14px;}
}
</style>

<!-- ══════════════════════════════════════════════════════════
     JAVASCRIPT
══════════════════════════════════════════════════════════ -->
<script>
const dropZone  = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
let selectedFile = null;
let currentBankType = 'BOC';
let pendingToken = null;
let pendingInfo  = null;

function escHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

/* ── Bank type switch ── */
function setBankType(type) {
    currentBankType = type;
    document.getElementById('bankType').value = type;

    document.getElementById('btnBOC').classList.toggle('active', type === 'BOC');
    document.getElementById('btnNDB').classList.toggle('active', type === 'NDB');
    document.getElementById('btnSAMPATH').classList.toggle('active', type === 'SAMPATH');

    document.getElementById('hintBOC').style.display     = type === 'BOC'     ? '' : 'none';
    document.getElementById('hintNDB').style.display     = type === 'NDB'     ? '' : 'none';
    document.getElementById('hintSAMPATH').style.display = type === 'SAMPATH' ? '' : 'none';

    if (type === 'NDB') {
        fileInput.setAttribute('accept', '.csv');
        document.getElementById('fileLabel').innerHTML = 'Statement File (CSV) <span style="color:#dc2626">*</span>';
        document.getElementById('dzSubText').textContent = 'NDB CSV export only · Max 10 MB';
        document.getElementById('uploadBtn').style.background = '#6d28d9';
    } else if (type === 'SAMPATH') {
        fileInput.setAttribute('accept', '.xls,.html,.htm');
        document.getElementById('fileLabel').innerHTML = 'Statement File (XLS / HTML export) <span style="color:#dc2626">*</span>';
        document.getElementById('dzSubText').textContent = 'Sampath "Full Statement" export (.xls/.html) · Max 10 MB';
        document.getElementById('uploadBtn').style.background = '#b91c1c';
    } else {
        fileInput.setAttribute('accept', '.xls,.xlsx,.csv');
        document.getElementById('fileLabel').innerHTML = 'Statement File (XLS / XLSX / CSV) <span style="color:#dc2626">*</span>';
        document.getElementById('dzSubText').textContent = 'Supports: .xls, .xlsx, .csv · Max 10 MB';
        document.getElementById('uploadBtn').style.background = '';
    }

    clearFile({ stopPropagation: function(){} });
}

/* ── Drag & drop ── */
if (dropZone) {
    dropZone.addEventListener('dragover',  e => { e.preventDefault(); dropZone.classList.add('dragover'); });
    dropZone.addEventListener('dragleave', ()  => dropZone.classList.remove('dragover'));
    dropZone.addEventListener('drop', e => {
        e.preventDefault(); dropZone.classList.remove('dragover');
        const f = e.dataTransfer.files[0];
        if (f) setFile(f);
    });
}
if (fileInput) {
    fileInput.addEventListener('change', () => { if (fileInput.files[0]) setFile(fileInput.files[0]); });
}

function setFile(f) {
    const ext = f.name.split('.').pop().toLowerCase();
    let allowed;
    if (currentBankType === 'NDB') allowed = ['csv'];
    else if (currentBankType === 'SAMPATH') allowed = ['xls','html','htm'];
    else allowed = ['xls','xlsx','csv'];

    if (!allowed.includes(ext)) {
        let msg = 'Only XLS, XLSX or CSV files are allowed.';
        if (currentBankType === 'NDB') msg = 'NDB statements must be CSV files.';
        else if (currentBankType === 'SAMPATH') msg = 'Sampath statements must be XLS/HTML exports.';
        showToast(msg, 'err');
        return;
    }
    if (f.size > 10 * 1024 * 1024) { showToast('File too large. Maximum 10 MB.','err'); return; }
    selectedFile = f;
    dropZone.classList.add('file-chosen');
    document.getElementById('dzInner').style.display   = 'none';
    document.getElementById('dzPreview').style.display = 'flex';
    document.getElementById('dzName').textContent = f.name;
    document.getElementById('dzSize').textContent = (f.size/1024/1024).toFixed(2)+' MB';
    const icon = document.getElementById('dzIcon');
    if (ext === 'csv') { icon.className='fa-solid fa-file-csv'; icon.style.color='#1d4ed8'; }
    else if (ext === 'html' || ext === 'htm') { icon.className='fa-solid fa-file-code'; icon.style.color='#b91c1c'; }
    else { icon.className='fa-solid fa-file-excel'; icon.style.color='#166534'; }
    document.getElementById('resultBox').style.display = 'none';
}

function clearFile(e) {
    if (e && e.stopPropagation) e.stopPropagation();
    selectedFile = null;
    if (fileInput) fileInput.value = '';
    dropZone.classList.remove('file-chosen');
    document.getElementById('dzInner').style.display   = '';
    document.getElementById('dzPreview').style.display = 'none';
}

function resetUploadBtn() {
    const b = document.getElementById('uploadBtn');
    b.disabled = false;
    b.innerHTML = '<i class="fa-solid fa-database"></i> Upload & Import to Database';
}

function showResult(res, cls) {
    const box = document.getElementById('resultBox');
    box.style.display = 'block';
    box.className = 'result-box ' + cls;
    const icon = cls === 'result-ok' ? 'fa-circle-check' : (cls === 'result-warn' ? 'fa-circle-info' : 'fa-circle-xmark');
    box.innerHTML = '<i class="fa-solid ' + icon + '"></i> ' + escHtml(res.msg);
}

function handleFinalResult(res) {
    if (res.ok) {
        showResult(res, 'result-ok');
        showToast(res.msg, 'ok');
        setTimeout(() => location.reload(), 2000);
    } else {
        showResult(res, 'result-err');
        showToast(res.msg, 'err');
    }
}

/* ── STEP 1: upload, parse, duplicate check ── */
function doUpload() {
    const account_id = document.getElementById('accountId')?.value;
    const date       = document.getElementById('statementDate')?.value;
    const bank_type  = document.getElementById('bankType')?.value;
    if (!account_id)   { showToast('Please select a bank account.','err'); return; }
    if (!date)         { showToast('Please select a statement date.','err'); return; }
    if (!selectedFile) { showToast('Please choose a file.','err'); return; }

    const fd = new FormData();
    fd.append('action','upload');
    fd.append('account_id', account_id);
    fd.append('statement_date', date);
    fd.append('bank_type', bank_type);
    fd.append('statement_file', selectedFile);

    document.getElementById('progressWrap').style.display = 'block';
    document.getElementById('progressBar').style.width    = '0%';
    document.getElementById('progressLabel').textContent  = 'Uploading…';
    document.getElementById('uploadBtn').disabled = true;
    document.getElementById('uploadBtn').innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing…';
    document.getElementById('resultBox').style.display = 'none';

    const xhr = new XMLHttpRequest();
    xhr.open('POST','bank_statements.php');

    xhr.upload.onprogress = e => {
        if (e.lengthComputable) {
            const pct = Math.round(e.loaded/e.total*100);
            document.getElementById('progressBar').style.width  = pct+'%';
            document.getElementById('progressLabel').textContent = 'Uploading… '+pct+'%';
        }
    };

    xhr.onload = () => {
        document.getElementById('progressLabel').textContent = 'Checking rows for duplicates…';
        document.getElementById('progressBar').style.width   = '100%';
        let res;
        try { res = JSON.parse(xhr.responseText); }
        catch(e) {
            showResult({msg:'Unexpected server response. Check PHP error logs.'}, 'result-err');
            resetUploadBtn();
            return;
        }
        if (res.ok && res.need_confirm) {
            openDupModal(res);
            document.getElementById('progressLabel').textContent = 'Waiting for your choice on duplicate rows…';
            return; // button stays disabled until the modal is closed
        }
        handleFinalResult(res);
        resetUploadBtn();
    };

    xhr.onerror = () => {
        showToast('Network error. Please try again.','err');
        resetUploadBtn();
    };

    xhr.send(fd);
}

/* ── Duplicate review modal ── */
function openDupModal(res) {
    pendingToken = res.token;
    pendingInfo  = res;

    document.getElementById('dupSummary').innerHTML =
        'The file has <strong>' + res.total + '</strong> transactions. ' +
        '<strong>' + res.new_count + '</strong> are new and will be imported. ' +
        '<strong>' + res.dup_count + '</strong> match rows already entered (Serial No not compared). ' +
        'Tick a duplicate to import it anyway — unticked rows are skipped.';

    const body = document.getElementById('dupBody');
    body.innerHTML = res.dups.map(d => `
        <tr class="dup-off">
            <td class="tc"><input type="checkbox" class="dup-cb" value="${d.idx}"></td>
            <td style="color:#9ca3af;">#${d.row_no}</td>
            <td style="white-space:nowrap;">${escHtml(d.td)}</td>
            <td style="max-width:260px;">${escHtml(d.desc)}</td>
            <td class="tr">${d.debit  ? '<span class="amt debit">'  + escHtml(d.debit)  + '</span>' : '<span class="muted">—</span>'}</td>
            <td class="tr">${d.credit ? '<span class="amt credit">' + escHtml(d.credit) + '</span>' : '<span class="muted">—</span>'}</td>
            <td class="tr">${escHtml(d.bal)}</td>
            <td style="font-size:11px;">${escHtml(d.serial || '—')}${d.ref ? '<div class="dup-where">' + escHtml(d.ref) + '</div>' : ''}</td>
            <td>
                <span class="dup-tag ${d.kind === 'db' ? 'db' : 'file'}">${d.kind === 'db' ? 'Already in DB' : 'Repeated in file'}</span>
                <div class="dup-where">${escHtml(d.where)}</div>
            </td>
        </tr>`).join('');

    body.querySelectorAll('.dup-cb').forEach(cb => cb.addEventListener('change', () => {
        cb.closest('tr').className = cb.checked ? 'dup-on' : 'dup-off';
        updateDupCount();
    }));

    const all = document.getElementById('dupCheckAll');
    all.checked = false;
    all.indeterminate = false;
    all.onchange = () => {
        body.querySelectorAll('.dup-cb').forEach(cb => {
            cb.checked = all.checked;
            cb.closest('tr').className = cb.checked ? 'dup-on' : 'dup-off';
        });
        updateDupCount();
    };

    updateDupCount();
    document.getElementById('dupModal').style.display = 'flex';
}

function updateDupCount() {
    const cbs = [...document.querySelectorAll('#dupBody .dup-cb')];
    const sel = cbs.filter(c => c.checked).length;
    const all = document.getElementById('dupCheckAll');
    all.checked = sel === cbs.length && cbs.length > 0;
    all.indeterminate = sel > 0 && sel < cbs.length;

    const toImport = pendingInfo.new_count + sel;
    const skip     = cbs.length - sel;
    document.getElementById('dupCountInfo').innerHTML =
        'Will import <strong>' + toImport + '</strong> rows (' + pendingInfo.new_count + ' new + ' + sel + ' duplicates) · ' +
        'skip <strong>' + skip + '</strong> duplicates';
    const btn = document.getElementById('dupConfirmBtn');
    btn.innerHTML = '<i class="fa-solid fa-database"></i> Import ' + toImport + ' rows';
    btn.disabled = toImport === 0;
}

function closeDupModal() {
    document.getElementById('dupModal').style.display = 'none';
    pendingToken = null;
    pendingInfo  = null;
}

/* ── STEP 2: confirm ── */
function confirmDupImport() {
    if (!pendingToken) return;
    const fd = new FormData();
    fd.append('action', 'confirm_import');
    fd.append('token', pendingToken);
    document.querySelectorAll('#dupBody .dup-cb:checked').forEach(cb => fd.append('import_dups[]', cb.value));

    const btn = document.getElementById('dupConfirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Importing…';

    fetch('bank_statements.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => { closeDupModal(); handleFinalResult(res); resetUploadBtn(); })
        .catch(() => {
            closeDupModal();
            showResult({msg:'Unexpected server response. Check PHP error logs.'}, 'result-err');
            resetUploadBtn();
        });
}

function cancelDupImport() {
    const token = pendingToken;
    closeDupModal();
    resetUploadBtn();
    document.getElementById('progressWrap').style.display = 'none';
    if (!token) return;
    const fd = new FormData();
    fd.append('action', 'cancel_import');
    fd.append('token', token);
    fetch('bank_statements.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => { showResult(res, 'result-warn'); showToast(res.msg, 'ok'); })
        .catch(() => {});
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && document.getElementById('dupModal')?.style.display === 'flex') cancelDupImport();
});

function showToast(msg, type) {
    const t = document.getElementById('toast');
    t.className = type==='ok'?'toast-ok':'toast-err';
    t.textContent=msg; t.style.display='block'; t.style.opacity='1';
    clearTimeout(t._t);
    t._t=setTimeout(()=>{ t.style.opacity='0'; setTimeout(()=>t.style.display='none',300); },3500);
}
</script>

<script>
/* reconcile filter on the transaction view */
document.querySelectorAll('.rc-filter button').forEach(function (b) {
    b.addEventListener('click', function () {
        document.querySelectorAll('.rc-filter button').forEach(function (x) { x.classList.toggle('on', x === b); });
        var f = b.dataset.rc;
        document.querySelectorAll('tr[data-rc]').forEach(function (tr) {
            tr.style.display = (f === 'all' || tr.dataset.rc === f) ? '' : 'none';
        });
    });
});
</script>

<?php if ($view_upload) bmr_render_modal($conn); ?>
<?php include 'footer.php'; ?>
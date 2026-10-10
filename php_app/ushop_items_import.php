<?php
include 'config.php';

/* ══════════════════════════════════════════════════════════
   ENSURE TABLES EXIST
══════════════════════════════════════════════════════════ */
mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS `ushop_items` (
  `id`               INT(11)        NOT NULL AUTO_INCREMENT,
  `product_code`     VARCHAR(100)   NOT NULL,
  `product_name`     VARCHAR(500)   DEFAULT NULL,
  `unilever_code`    VARCHAR(50)    DEFAULT NULL,
  `supplier`         VARCHAR(255)   DEFAULT NULL,
  `location`         VARCHAR(255)   DEFAULT NULL,
  `department`       VARCHAR(255)   DEFAULT NULL,
  `category`         VARCHAR(255)   DEFAULT NULL,
  `sub_category`     VARCHAR(255)   DEFAULT NULL,
  `sub_category2`    VARCHAR(255)   DEFAULT NULL,
  `level_code`       VARCHAR(100)   DEFAULT NULL,
  `ref_code1`        VARCHAR(100)   DEFAULT NULL,
  `ref_code2`        VARCHAR(100)   DEFAULT NULL,
  `ref_code3`        VARCHAR(100)   DEFAULT NULL,
  `mrp`              DECIMAL(15,4)  DEFAULT NULL,
  `cost_price`       DECIMAL(15,4)  DEFAULT NULL,
  `created_at`       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_code` (`product_code`),
  KEY `idx_unilever_code` (`unilever_code`),
  KEY `idx_category`      (`category`(100)),
  KEY `idx_department`    (`department`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS `ushop_items_imports` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `filename`     VARCHAR(255) NOT NULL,
  `import_date`  DATE         NOT NULL,
  `total_rows`   INT(11)      NOT NULL DEFAULT 0,
  `new_items`    INT(11)      NOT NULL DEFAULT 0,
  `updated_items`INT(11)      NOT NULL DEFAULT 0,
  `skipped_rows` INT(11)      NOT NULL DEFAULT 0,
  `imported_by`  VARCHAR(100) DEFAULT NULL,
  `imported_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `note`         TEXT         DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_import_date` (`import_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS `ushop_stock_history` (
  `id`            INT(11)        NOT NULL AUTO_INCREMENT,
  `import_id`     INT(11)        NOT NULL,
  `product_code`  VARCHAR(100)   NOT NULL,
  `unilever_code` VARCHAR(50)    DEFAULT NULL,
  `product_name`  VARCHAR(500)   DEFAULT NULL,
  `txn_type`      ENUM('OPENING','STOCK_IN','STOCK_OUT','ADJUSTMENT') NOT NULL DEFAULT 'OPENING',
  `qty`           DECIMAL(15,4)  DEFAULT NULL,
  `mrp`           DECIMAL(15,4)  DEFAULT NULL,
  `cost_price`    DECIMAL(15,4)  DEFAULT NULL,
  `total_mrp`     DECIMAL(18,4)  DEFAULT NULL,
  `total_cost`    DECIMAL(18,4)  DEFAULT NULL,
  `avg_cost`      DECIMAL(15,4)  DEFAULT NULL,
  `txn_date`      DATE           NOT NULL,
  `reference`     VARCHAR(255)   DEFAULT NULL,
  `note`          TEXT           DEFAULT NULL,
  `created_at`    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_import_id`     (`import_id`),
  KEY `idx_product_code`  (`product_code`),
  KEY `idx_unilever_code` (`unilever_code`),
  KEY `idx_txn_date`      (`txn_date`),
  KEY `idx_txn_type`      (`txn_type`),
  CONSTRAINT `fk_ssh_import` FOREIGN KEY (`import_id`)
    REFERENCES `ushop_items_imports`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* ══════════════════════════════════════════════════════════
   HELPERS
══════════════════════════════════════════════════════════ */
function safeStr($v, $max = 255) {
    if ($v === null) return null;
    $s = trim((string)$v);
    return $s === '' ? null : mb_substr($s, 0, $max);
}
function safeNum($v) {
    if ($v === null || $v === '') return null;
    $v = str_replace(',', '', trim((string)$v));
    return is_numeric($v) ? $v : null;
}
function esc($conn, $v) {
    return $v === null ? 'NULL' : "'".mysqli_real_escape_string($conn, (string)$v)."'";
}

/**
 * Extract Unilever code from product name.
 * Supports BOTH positions:
 *   Suffix : "AXE AMBER WOOD 80ML-3539203"  → 3539203   (Inventory Stock Balance format)
 *   Prefix : "3904001-CLEAR SHMP CSC 180ML" → 3904001
 * Returns the numeric code, or null if no match.
 */
function extractUnileverCode($productName) {
    if (!$productName) return null;
    $name = trim($productName);
    if (preg_match('/-(\d{5,10})\s*$/', $name, $m)) {   // code at END of name
        return $m[1];
    }
    if (preg_match('/^(\d{5,10})-/', $name, $m)) {      // code at START of name
        return $m[1];
    }
    return null;
}

/**
 * Convert an Excel cell reference like "BC12" → 0-based column index.
 */
function colLetterToIndex($ref) {
    if (!preg_match('/^([A-Za-z]+)/', $ref, $m)) return 0;
    $letters = strtoupper($m[1]);
    $n = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return $n - 1;
}

/**
 * Normalize numeric strings in scientific notation (e.g. barcodes
 * stored as numbers: "4.792081028252E+12" → "4792081028252").
 */
function normalizeCellValue($v) {
    if ($v !== '' && preg_match('/^-?\d+(\.\d+)?E[+\-]?\d+$/i', $v)) {
        return sprintf('%.0f', (float)$v);
    }
    return $v;
}

/**
 * Parse a real XLSX (Office Open XML zip) file — pure PHP, no Composer needed.
 * Handles shared strings, inline strings, numbers, and column gaps.
 */
function parseXlsxFile($filePath) {
    if (!class_exists('ZipArchive')) {
        return ['error' => 'PHP ZipArchive extension is required to read .xlsx files. Ask your host to enable php-zip.'];
    }
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return ['error' => 'Cannot open XLSX file (invalid zip).'];
    }

    // 1) Shared strings
    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $sx = simplexml_load_string($ssXml);
        if ($sx) {
            foreach ($sx->si as $si) {
                if (isset($si->t)) {
                    $shared[] = (string)$si->t;
                } else {
                    $txt = '';
                    foreach ($si->r as $r) $txt .= (string)$r->t;
                    $shared[] = $txt;
                }
            }
        }
    }

    // 2) First worksheet
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nm = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $nm)) {
                $sheetXml = $zip->getFromName($nm);
                break;
            }
        }
    }
    $zip->close();

    if ($sheetXml === false) return ['error' => 'No worksheet found inside the XLSX file.'];

    $sx = simplexml_load_string($sheetXml);
    if (!$sx || !isset($sx->sheetData)) return ['error' => 'Cannot parse worksheet XML inside XLSX.'];

    $rows = [];
    foreach ($sx->sheetData->row as $rowNode) {
        $cells = [];
        foreach ($rowNode->c as $c) {
            $attrs = $c->attributes();
            $ref   = isset($attrs['r']) ? (string)$attrs['r'] : '';
            $type  = isset($attrs['t']) ? (string)$attrs['t'] : '';
            $idx   = $ref !== '' ? colLetterToIndex($ref) : count($cells);

            if ($type === 's') {                       // shared string
                $si  = isset($c->v) ? (int)$c->v : -1;
                $val = ($si >= 0 && isset($shared[$si])) ? $shared[$si] : '';
            } elseif ($type === 'inlineStr') {         // inline string
                $val = isset($c->is->t) ? (string)$c->is->t : '';
            } else {                                   // number / bool / formula result
                $val = isset($c->v) ? (string)$c->v : '';
            }
            $val = normalizeCellValue($val);

            while (count($cells) < $idx) $cells[] = '';
            $cells[$idx] = $val;
        }
        $rows[] = $cells;
    }
    return $rows;
}

/**
 * Parse SpreadsheetML (.xls saved as XML) file — original format support.
 */
function parseXlsXml($filePath) {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($filePath);
    if (!$xml) return ['error' => 'Cannot parse XLS (XML) file'];

    $ns   = 'urn:schemas-microsoft-com:office:spreadsheet';
    $rows = [];

    foreach ($xml->Worksheet as $ws) {
        foreach ($ws->Table->Row as $rowNode) {
            $cells = [];
            foreach ($rowNode->Cell as $cellNode) {
                $attrs = $cellNode->attributes($ns);
                $idx   = isset($attrs['Index']) ? (int)$attrs['Index'] - 1 : null;
                $data  = $cellNode->Data;
                $val   = $data ? (string)$data : '';
                if ($idx !== null) {
                    while (count($cells) < $idx) $cells[] = '';
                    $cells[$idx] = $val;
                } else {
                    $cells[] = $val;
                }
            }
            $rows[] = $cells;
        }
        break; // only first worksheet
    }
    return $rows;
}

/**
 * Parse an HTML-table "xls" export (many POS systems export these).
 */
function parseHtmlTable($filePath) {
    $html = file_get_contents($filePath);
    if (!$html) return ['error' => 'Cannot read HTML file.'];
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
    $rows = [];
    foreach ($dom->getElementsByTagName('tr') as $tr) {
        $cells = [];
        foreach ($tr->childNodes as $td) {
            $tag = strtolower($td->nodeName);
            if ($tag === 'td' || $tag === 'th') {
                $cells[] = normalizeCellValue(trim($td->textContent));
            }
        }
        if ($cells) $rows[] = $cells;
    }
    return $rows ?: ['error' => 'No table rows found in HTML file.'];
}

/* ──────────────────────────────────────────────────────────
   PURE-PHP BINARY .XLS (BIFF8 / Excel 97-2003) READER
   No Composer / PhpSpreadsheet needed.
   Reads the OLE2 compound file, extracts the Workbook stream,
   and decodes cell records (SST, LABELSST, NUMBER, RK, MULRK,
   LABEL, FORMULA cached results) from the FIRST worksheet.
────────────────────────────────────────────────────────── */
function biffU16($s, $o) { return ord($s[$o]) | (ord($s[$o+1]) << 8); }
function biffU32($s, $o) {
    return ord($s[$o]) | (ord($s[$o+1]) << 8) | (ord($s[$o+2]) << 16) | (ord($s[$o+3]) << 24);
}
function biffS32($s, $o) {
    $v = biffU32($s, $o);
    return ($v >= 0x80000000) ? $v - 4294967296 : $v;
}
function biffDouble($s, $o) {
    $u = unpack('e', substr($s, $o, 8)); // little-endian double (PHP 7.2+)
    return $u[1];
}
function biffFmtNum($v) {
    if ($v == floor($v) && abs($v) < 1e15) return sprintf('%.0f', $v);
    $str = number_format($v, 6, '.', '');
    return rtrim(rtrim($str, '0'), '.');
}
function biffToUtf8($bytes, $wide) {
    if ($wide) {
        if (function_exists('mb_convert_encoding')) return mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE');
        return iconv('UTF-16LE', 'UTF-8//IGNORE', $bytes);
    }
    // compressed strings = low bytes of UTF-16 ≈ ISO-8859-1
    if (function_exists('mb_convert_encoding')) return mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1');
    return iconv('ISO-8859-1', 'UTF-8//IGNORE', $bytes);
}
function biffRkNum($rk) {
    $f100 = $rk & 1;
    if ($rk & 2) {                       // 30-bit signed integer
        $v = $rk >> 2;
        if ($v & 0x20000000) $v -= 0x40000000;
        $val = (float)$v;
    } else {                             // top 30 bits of an IEEE double
        $val = unpack('e', "\x00\x00\x00\x00" . pack('V', $rk & 0xFFFFFFFC))[1];
    }
    return $f100 ? $val / 100 : $val;
}

/** Extract the "Workbook" (or "Book") stream from an OLE2 compound file. */
function oleExtractWorkbookStream($data) {
    if (strlen($data) < 512 || substr($data, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") return null;

    $sectSize     = 1 << biffU16($data, 30);
    $miniSize     = 1 << biffU16($data, 32);
    $dirStart     = biffS32($data, 48);
    $miniCutoff   = biffU32($data, 56);
    $miniFatStart = biffS32($data, 60);
    $difatStart   = biffS32($data, 68);
    $numDifat     = biffU32($data, 72);

    $sector = function($n) use ($data, $sectSize) {
        return substr($data, 512 + $n * $sectSize, $sectSize);
    };

    // DIFAT: first 109 entries live in the header, rest in DIFAT sectors
    $difat = [];
    for ($i = 0; $i < 109; $i++) $difat[] = biffS32($data, 76 + $i * 4);
    $s = $difatStart;
    $perSect = intdiv($sectSize, 4);
    for ($d = 0; $d < $numDifat && $s >= 0; $d++) {
        $blk = $sector($s);
        for ($i = 0; $i < $perSect - 1; $i++) $difat[] = biffS32($blk, $i * 4);
        $s = biffS32($blk, ($perSect - 1) * 4);
    }

    // FAT
    $fat = [];
    foreach ($difat as $fs) {
        if ($fs < 0) continue;
        $blk = $sector($fs);
        for ($i = 0; $i < $perSect; $i++) $fat[] = biffS32($blk, $i * 4);
    }

    $chain = function($start, $size, $table, $reader) {
        $out = ''; $s = $start;
        $guard = 0;
        while ($s >= 0 && $guard++ < 1000000) {
            $out .= $reader($s);
            if (!isset($table[$s])) break;
            $s = $table[$s];
        }
        return $size === null ? $out : substr($out, 0, $size);
    };

    // Directory
    $directory = $chain($dirStart, null, $fat, $sector);
    $entries = [];
    for ($i = 0; $i + 128 <= strlen($directory); $i += 128) {
        $e = substr($directory, $i, 128);
        $nlen = biffU16($e, 64);
        if ($nlen === 0) continue;
        $name  = biffToUtf8(substr($e, 0, max(0, $nlen - 2)), true);
        $etype = ord($e[66]);
        $start = biffS32($e, 116);
        $size  = biffU32($e, 120);
        $entries[] = [$name, $etype, $start, $size];
    }

    // Root entry → mini stream
    $root = null;
    foreach ($entries as $e) if ($e[1] === 5) { $root = $e; break; }
    $miniStream = $root ? $chain($root[2], $root[3], $fat, $sector) : '';

    // Mini FAT
    $miniFat = [];
    if ($miniFatStart >= 0) {
        $mf = $chain($miniFatStart, null, $fat, $sector);
        for ($i = 0; $i + 4 <= strlen($mf); $i += 4) $miniFat[] = biffS32($mf, $i);
    }
    $miniSector = function($n) use ($miniStream, $miniSize) {
        return substr($miniStream, $n * $miniSize, $miniSize);
    };

    foreach (['Workbook', 'Book'] as $want) {
        foreach ($entries as $e) {
            if ($e[0] === $want && $e[1] === 2) {
                if ($e[3] < $miniCutoff) return $chain($e[2], $e[3], $miniFat, $miniSector);
                return $chain($e[2], $e[3], $fat, $sector);
            }
        }
    }
    return null;
}

/** Parse the SST record (+ its CONTINUE records) into an array of strings. */
function biffParseSst($recs, $idx) {
    $parts = [$recs[$idx][1]];
    for ($j = $idx + 1; $j < count($recs) && $recs[$j][0] === 0x003C; $j++) {
        $parts[] = $recs[$j][1];
    }
    $strings = [];
    $total = biffU32($parts[0], 4);
    $pi = 0; $off = 8;

    for ($k = 0; $k < $total; $k++) {
        // string header (cch + grbit) never usefully splits; guard anyway
        if ($off + 3 > strlen($parts[$pi])) { $pi++; $off = 0; }
        if ($pi >= count($parts)) break;

        $cch   = biffU16($parts[$pi], $off); $off += 2;
        $grbit = ord($parts[$pi][$off]);     $off += 1;
        $crun = 0; $cbext = 0;
        if ($grbit & 8) { $crun  = biffU16($parts[$pi], $off); $off += 2; }
        if ($grbit & 4) { $cbext = biffU32($parts[$pi], $off); $off += 4; }

        $out = '';
        $wide = $grbit & 1;
        $remaining = $cch;
        while ($remaining > 0) {
            if ($off >= strlen($parts[$pi])) {
                $pi++; if ($pi >= count($parts)) break;
                // each CONTINUE restates the wide/compressed flag in its first byte
                $wide = ord($parts[$pi][0]) & 1;
                $off = 1;
            }
            $avail = strlen($parts[$pi]) - $off;
            if ($wide) {
                $take = min($remaining, intdiv($avail, 2));
                if ($take <= 0) { $pi++; if ($pi >= count($parts)) break; $wide = ord($parts[$pi][0]) & 1; $off = 1; continue; }
                $out .= biffToUtf8(substr($parts[$pi], $off, $take * 2), true);
                $off += $take * 2;
            } else {
                $take = min($remaining, $avail);
                if ($take <= 0) { $pi++; if ($pi >= count($parts)) break; $wide = ord($parts[$pi][0]) & 1; $off = 1; continue; }
                $out .= biffToUtf8(substr($parts[$pi], $off, $take), false);
                $off += $take;
            }
            $remaining -= $take;
        }

        // skip rich-text runs & extended data (can also span CONTINUEs)
        $skip = $crun * 4 + $cbext;
        while ($skip > 0 && $pi < count($parts)) {
            if ($off >= strlen($parts[$pi])) { $pi++; $off = 0; continue; }
            $t = min($skip, strlen($parts[$pi]) - $off);
            $off += $t; $skip -= $t;
        }
        $strings[] = $out;
    }
    return $strings;
}

/**
 * Parse an old binary .xls (BIFF8) file — first worksheet only.
 * Returns rows[][] of string values, or ['error'=>...].
 */
function parseBinaryXls($filePath) {
    $data = @file_get_contents($filePath);
    if ($data === false) return ['error' => 'Cannot read uploaded file.'];

    $stream = oleExtractWorkbookStream($data);
    if ($stream === null) return ['error' => 'Could not locate the Workbook stream inside the .xls file.'];

    // Split into records
    $recs = [];
    $pos = 0; $n = strlen($stream);
    while ($pos + 4 <= $n) {
        $rid  = biffU16($stream, $pos);
        $rlen = biffU16($stream, $pos + 2);
        $recs[] = [$rid, substr($stream, $pos + 4, $rlen)];
        $pos += 4 + $rlen;
    }

    $sst = [];
    $cells = [];          // $cells[row][col] = value
    $sheetState = 0;      // 0 = workbook globals, 1 = first sheet, 2 = done

    foreach ($recs as $i => $rec) {
        if ($sheetState === 2) break;
        $rid = $rec[0]; $d = $rec[1];

        if ($rid === 0x00FC && $sheetState === 0) {            // SST
            $sst = biffParseSst($recs, $i);
        } elseif ($rid === 0x0809) {                            // BOF
            if (strlen($d) >= 4 && biffU16($d, 2) === 0x0010) $sheetState = 1;
        } elseif ($rid === 0x000A) {                            // EOF
            if ($sheetState === 1) $sheetState = 2;
        } elseif ($rid === 0x00FD && strlen($d) >= 10) {        // LABELSST
            $r = biffU16($d, 0); $c = biffU16($d, 2);
            $isst = biffU32($d, 6);
            $cells[$r][$c] = $sst[$isst] ?? '';
        } elseif ($rid === 0x0203 && strlen($d) >= 14) {        // NUMBER
            $r = biffU16($d, 0); $c = biffU16($d, 2);
            $cells[$r][$c] = biffFmtNum(biffDouble($d, 6));
        } elseif ($rid === 0x027E && strlen($d) >= 10) {        // RK
            $r = biffU16($d, 0); $c = biffU16($d, 2);
            $cells[$r][$c] = biffFmtNum(biffRkNum(biffU32($d, 6)));
        } elseif ($rid === 0x00BD && strlen($d) >= 6) {         // MULRK
            $r = biffU16($d, 0); $cf = biffU16($d, 2);
            $cl = biffU16($d, strlen($d) - 2);
            for ($k = 0; $k <= $cl - $cf; $k++) {
                $rk = biffU32($d, 4 + $k * 6 + 2);
                $cells[$r][$cf + $k] = biffFmtNum(biffRkNum($rk));
            }
        } elseif ($rid === 0x0204 && strlen($d) >= 9) {         // LABEL (inline string)
            $r = biffU16($d, 0); $c = biffU16($d, 2);
            $cch = biffU16($d, 6);
            $grbit = ord($d[8]);
            $cells[$r][$c] = ($grbit & 1)
                ? biffToUtf8(substr($d, 9, $cch * 2), true)
                : biffToUtf8(substr($d, 9, $cch), false);
        } elseif ($rid === 0x0006 && strlen($d) >= 14) {        // FORMULA (cached numeric result)
            $r = biffU16($d, 0); $c = biffU16($d, 2);
            if (biffU16($d, 12) !== 0xFFFF) {
                $cells[$r][$c] = biffFmtNum(biffDouble($d, 6));
            }
        }
    }

    if (!$cells) return ['error' => 'No cell data found in the .xls file.'];

    ksort($cells);
    $maxRow = max(array_keys($cells));
    $rows = [];
    for ($r = 0; $r <= $maxRow; $r++) {
        $line = [];
        if (isset($cells[$r]) && $cells[$r]) {
            $maxC = max(array_keys($cells[$r]));
            for ($c = 0; $c <= $maxC; $c++) $line[] = $cells[$r][$c] ?? '';
        }
        $rows[] = $line;
    }
    return $rows;
}

/**
 * MASTER PARSER — detects the real file format by magic bytes,
 * regardless of the file extension, and routes to the right parser.
 *
 *   PK..           → real .xlsx (Office Open XML zip)
 *   D0 CF 11 E0    → old BINARY .xls (BIFF / OLE2)  → PhpSpreadsheet if available, else clear message
 *   <?xml / <Workbook → SpreadsheetML .xls
 *   <html / <table → HTML table export
 */
function parseAnySpreadsheet($filePath) {
    $head = @file_get_contents($filePath, false, null, 0, 2048);
    if ($head === false || $head === '') return ['error' => 'Cannot read uploaded file.'];

    // Real XLSX (zip)
    if (substr($head, 0, 4) === "PK\x03\x04") {
        return parseXlsxFile($filePath);
    }

    // Old binary .xls (BIFF, OLE2 compound document) — e.g. files saved by
    // desktop Excel as "Excel 97-2003 Workbook (.xls)".
    // Handled directly by the built-in pure-PHP BIFF8 reader — no Composer needed.
    if (substr($head, 0, 4) === "\xD0\xCF\x11\xE0") {
        $rows = parseBinaryXls($filePath);
        if (!isset($rows['error'])) return $rows;

        // Fallback: PhpSpreadsheet if it happens to be installed
        $auto = __DIR__.'/vendor/autoload.php';
        if (file_exists($auto)) require_once $auto;
        if (class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) {
            try {
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xls');
                $reader->setReadDataOnly(true);
                $ss   = $reader->load($filePath);
                $data = $ss->getActiveSheet()->toArray(null, false, false, false);
                $out = [];
                foreach ($data as $r) {
                    $cells = [];
                    foreach ($r as $v) {
                        if (is_float($v) && $v == floor($v) && abs($v) >= 1e10) {
                            $v = sprintf('%.0f', $v); // keep long barcodes intact
                        }
                        $cells[] = $v === null ? '' : (string)$v;
                    }
                    $out[] = $cells;
                }
                return $out;
            } catch (\Throwable $e) {
                return ['error' => 'Could not read the binary .xls file: '.$e->getMessage()];
            }
        }
        return ['error' => $rows['error'].' As a workaround, open the file in Excel and Save As "Excel Workbook (*.xlsx)", then upload the .xlsx.'];
    }

    // SpreadsheetML XML (.xls saved as XML)
    $trim = ltrim($head);
    if (stripos($trim, '<?xml') === 0 || stripos($head, '<Workbook') !== false || stripos($head, 'mso-application') !== false) {
        return parseXlsXml($filePath);
    }

    // HTML table masquerading as .xls
    if (stripos($trim, '<html') === 0 || stripos($trim, '<!doctype html') === 0 || stripos($head, '<table') !== false) {
        return parseHtmlTable($filePath);
    }

    return ['error' => 'Unrecognized file format. Please upload a .xlsx file, an XML-based .xls, or re-save the report as .xlsx in Excel.'];
}

/* ══════════════════════════════════════════════════════════
   AJAX UPLOAD HANDLER
══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    header('Content-Type: application/json');

    $import_date = trim($_POST['import_date'] ?? '');
    $note        = trim($_POST['note'] ?? '');
    $mode        = trim($_POST['mode'] ?? 'upsert'); // upsert | new_only
    $include_zero = ($_POST['include_zero'] ?? '0') === '1'; // record zero-qty items in stock history too

    if (!$import_date || !strtotime($import_date)) {
        echo json_encode(['ok'=>false,'msg'=>'Please select a valid Import Date.']);
        exit;
    }
    if (!isset($_FILES['xls_file']) || $_FILES['xls_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok'=>false,'msg'=>'File upload failed. Please try again.']);
        exit;
    }

    $file = $_FILES['xls_file'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xls','xlsx'])) {
        echo json_encode(['ok'=>false,'msg'=>'Only .xls or .xlsx files are allowed.']);
        exit;
    }

    $tmp  = $file['tmp_name'];
    $rows = parseAnySpreadsheet($tmp);

    if (isset($rows['error'])) {
        echo json_encode(['ok'=>false,'msg'=>$rows['error']]);
        exit;
    }

    // Find header row — look for "Product Code"
    $header_row_idx = null;
    foreach ($rows as $ri => $row) {
        foreach ($row as $ci => $cell) {
            if (strtolower(trim($cell)) === 'product code') {
                $header_row_idx = $ri;
                break 2;
            }
        }
    }

    if ($header_row_idx === null) {
        echo json_encode(['ok'=>false,'msg'=>'Could not find header row. Expected "Product Code" column.']);
        exit;
    }

    $header = $rows[$header_row_idx];
    $col    = [];
    foreach ($header as $ci => $h) {
        $col[strtolower(trim($h))] = $ci;
    }

    // Map columns (flexible) — matches the "Inventory Stock Balance" report header:
    // Product Code | Product Name | Supplier | Location | Department | Category |
    // SubCategory | SubCategory2 | Level Code | Reference Code 1..6 | Qty |
    // Sale Price | Cost Price | Selling Value | Cost Value | Avg Cost | Avg Cost Value
    $CM = [
        'product_code'  => $col['product code']    ?? 0,
        'product_name'  => $col['product name']    ?? 1,
        'supplier'      => $col['supplier']        ?? 2,
        'location'      => $col['location']        ?? 3,
        'department'    => $col['department']      ?? 4,
        'category'      => $col['category']        ?? 5,
        'sub_cat'       => $col['subcategory']     ?? ($col['sub category'] ?? 6),
        'sub_cat2'      => $col['subcategory2']    ?? ($col['sub category2'] ?? 7),
        'level_code'    => $col['level code']      ?? 8,
        'ref1'          => $col['reference code 1']?? 9,
        'ref2'          => $col['reference code 2']?? 10,
        'ref3'          => $col['reference code 3']?? 11,
        'qty'           => $col['qty']             ?? ($col['balance qty'] ?? 15),
        'mrp'           => $col['mrp']             ?? ($col['sale price'] ?? ($col['selling price'] ?? 16)),
        'cost'          => $col['cost price']      ?? ($col['cost'] ?? 17),
        'total_mrp'     => $col['total mrp']       ?? ($col['selling value'] ?? ($col['total mrp value'] ?? 18)),
        'total_cost'    => $col['total cost']      ?? ($col['cost value'] ?? ($col['total cost value'] ?? 19)),
        'avg_cost'      => $col['avg cost']        ?? ($col['average cost'] ?? 20),
    ];

    // Create import record
    $fname = mysqli_real_escape_string($conn, $file['name']);
    $dnote = mysqli_real_escape_string($conn, $note);
    $ddate = mysqli_real_escape_string($conn, $import_date);
    mysqli_query($conn, "INSERT INTO ushop_items_imports (filename,import_date,note) VALUES ('$fname','$ddate','$dnote')");
    $import_id = mysqli_insert_id($conn);

    if (!$import_id) {
        echo json_encode(['ok'=>false,'msg'=>'Failed to create import record in DB.']);
        exit;
    }

    $new_items    = 0;
    $updated      = 0;
    $skipped      = 0;
    $stock_batch  = [];
    $batch_size   = 200;

    $flush_stock = function() use (&$stock_batch, $conn) {
        if (!$stock_batch) return;
        $vals = implode(',', $stock_batch);
        mysqli_query($conn, "INSERT INTO ushop_stock_history
          (import_id,product_code,unilever_code,product_name,txn_type,qty,mrp,cost_price,
           total_mrp,total_cost,avg_cost,txn_date,reference,note)
          VALUES $vals");
        $stock_batch = [];
    };

    $g = function($row, $idx) {
        return (isset($row[$idx]) && $row[$idx] !== '') ? $row[$idx] : null;
    };

    for ($ri = $header_row_idx + 1; $ri < count($rows); $ri++) {
        $row = $rows[$ri];

        $product_code = safeStr($g($row, $CM['product_code']), 100);
        if (!$product_code) { $skipped++; continue; }

        $product_name = safeStr($g($row, $CM['product_name']), 500);

        // Extract Unilever code from product name (suffix OR prefix)
        $unilever_code = extractUnileverCode($product_name);

        $supplier    = safeStr($g($row, $CM['supplier']));
        $location    = safeStr($g($row, $CM['location']));
        // The report prepends a running row number to the Location column
        // (e.g. "2 Unilever Sri Lanka Limited") — strip it.
        if ($location !== null) {
            $location = preg_replace('/^\d+\s+(?=[A-Za-z])/', '', $location);
        }
        $department  = safeStr($g($row, $CM['department']));
        $category    = safeStr($g($row, $CM['category']));
        $sub_cat     = safeStr($g($row, $CM['sub_cat']));
        $sub_cat2    = safeStr($g($row, $CM['sub_cat2']));
        $level_code  = safeStr($g($row, $CM['level_code']));
        $ref1        = safeStr($g($row, $CM['ref1']));
        $ref2        = safeStr($g($row, $CM['ref2']));
        $ref3        = safeStr($g($row, $CM['ref3']));
        $qty         = safeNum($g($row, $CM['qty']));
        $mrp         = safeNum($g($row, $CM['mrp']));
        $cost        = safeNum($g($row, $CM['cost']));
        $total_mrp   = safeNum($g($row, $CM['total_mrp']));
        $total_cost  = safeNum($g($row, $CM['total_cost']));
        $avg_cost    = safeNum($g($row, $CM['avg_cost']));

        // Skip if qty is null AND mrp is null (likely a blank/total row)
        if ($qty === null && $mrp === null && !$product_name) { $skipped++; continue; }

        // Upsert into ushop_items
        $existing = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id FROM ushop_items WHERE product_code='".mysqli_real_escape_string($conn,$product_code)."' LIMIT 1"
        ));

        if ($existing) {
            if ($mode === 'upsert') {
                mysqli_query($conn, "UPDATE ushop_items SET
                  product_name=".esc($conn,$product_name).",
                  unilever_code=".esc($conn,$unilever_code).",
                  supplier=".esc($conn,$supplier).",
                  location=".esc($conn,$location).",
                  department=".esc($conn,$department).",
                  category=".esc($conn,$category).",
                  sub_category=".esc($conn,$sub_cat).",
                  sub_category2=".esc($conn,$sub_cat2).",
                  level_code=".esc($conn,$level_code).",
                  ref_code1=".esc($conn,$ref1).",
                  ref_code2=".esc($conn,$ref2).",
                  ref_code3=".esc($conn,$ref3).",
                  mrp=".($mrp ?? 'NULL').",
                  cost_price=".($cost ?? 'NULL')."
                  WHERE product_code='".mysqli_real_escape_string($conn,$product_code)."'");
                $updated++;
            } else {
                $skipped++; continue;
            }
        } else {
            mysqli_query($conn, "INSERT INTO ushop_items
              (product_code,product_name,unilever_code,supplier,location,department,
               category,sub_category,sub_category2,level_code,ref_code1,ref_code2,ref_code3,mrp,cost_price)
              VALUES (".implode(',', [
                esc($conn,$product_code),
                esc($conn,$product_name),
                esc($conn,$unilever_code),
                esc($conn,$supplier),
                esc($conn,$location),
                esc($conn,$department),
                esc($conn,$category),
                esc($conn,$sub_cat),
                esc($conn,$sub_cat2),
                esc($conn,$level_code),
                esc($conn,$ref1),
                esc($conn,$ref2),
                esc($conn,$ref3),
                ($mrp  ?? 'NULL'),
                ($cost ?? 'NULL'),
              ]).")");
            $new_items++;
        }

        // Insert opening stock into stock history.
        // Default: only items with qty > 0. With "include zero stock" on,
        // every item gets an OPENING row (missing qty is recorded as 0).
        $qty_for_stock = $qty;
        if ($include_zero && $qty_for_stock === null) $qty_for_stock = 0;
        if ($qty_for_stock !== null && ($qty_for_stock > 0 || $include_zero)) {
            $stock_batch[] = sprintf("(%d,%s,%s,%s,'OPENING',%s,%s,%s,%s,%s,%s,'%s',%s,%s)",
                $import_id,
                esc($conn, $product_code),
                esc($conn, $unilever_code),
                esc($conn, $product_name),
                $qty_for_stock,
                ($mrp       ?? 'NULL'),
                ($cost      ?? 'NULL'),
                ($total_mrp ?? 'NULL'),
                ($total_cost?? 'NULL'),
                ($avg_cost  ?? 'NULL'),
                mysqli_real_escape_string($conn, $import_date),
                esc($conn, 'Import: '.$file['name']),
                esc($conn, $note)
            );
            if (count($stock_batch) >= $batch_size) $flush_stock();
        }
    }

    $flush_stock();

    $total_rows = $new_items + $updated;
    mysqli_query($conn, "UPDATE ushop_items_imports SET
      total_rows=$total_rows, new_items=$new_items, updated_items=$updated, skipped_rows=$skipped
      WHERE id=$import_id");

    if ($total_rows === 0) {
        mysqli_query($conn, "DELETE FROM ushop_items_imports WHERE id=$import_id");
        echo json_encode(['ok'=>false,'msg'=>"No valid rows found. Skipped: $skipped. Check file format."]);
        exit;
    }

    echo json_encode(['ok'=>true,'msg'=>"Import complete — $new_items new items, $updated updated".($skipped?" ($skipped skipped)":'').". Opening stock recorded."]);
    exit;
}

/* ══════════════════════════════════════════════════════════
   PAGE STATS
══════════════════════════════════════════════════════════ */
$stats = mysqli_fetch_assoc(mysqli_query($conn,"
  SELECT
    (SELECT COUNT(*) FROM ushop_items) AS total_items,
    (SELECT COUNT(*) FROM ushop_items WHERE unilever_code IS NOT NULL) AS items_with_code,
    (SELECT COUNT(*) FROM ushop_items_imports) AS total_imports,
    (SELECT COALESCE(SUM(qty),0) FROM ushop_stock_history WHERE txn_type='OPENING') AS opening_stock_qty,
    (SELECT MAX(imported_at) FROM ushop_items_imports) AS last_import
")) ?: [];

include 'header.php';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>UShop Items Import</title>
</head>
<body>
<style>
*{box-sizing:border-box;}

/* ── Layout ── */
.page-wrap{max-width:1200px;margin:0 auto;padding:0 8px;}
.ph-row{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:22px;}
.breadcrumb{display:flex;align-items:center;gap:6px;font-size:11.5px;color:#9ca3af;margin-bottom:14px;flex-wrap:wrap;}
.breadcrumb a{color:#0e7490;text-decoration:none;font-weight:600;}
.breadcrumb a:hover{text-decoration:underline;}
.breadcrumb .sep{color:#d1d5db;}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-teal{background:#0e7490;color:#fff;}.btn-teal:hover{background:#155e75;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-danger{background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;}
.btn-sm{padding:5px 10px;font-size:11.5px;}
.btn-green{background:#15803d;color:#fff;}.btn-green:hover{background:#166534;}

/* ── Summary Cards ── */
.sum-cards{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:22px;}
.sum-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 22px;flex:1;min-width:155px;box-shadow:0 1px 4px rgba(0,0,0,.04);border-top:3px solid #0e7490;}
.sum-card-label{font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.sum-card-val{font-size:22px;font-weight:700;color:#0e7490;}

/* ── Cards ── */
.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin-bottom:22px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
.card-hdr{padding:18px 24px;border-bottom:1px solid #f3f4f6;background:linear-gradient(135deg,#f0fdfa 0%,#fff 100%);border-radius:12px 12px 0 0;}
.card-hdr h3{margin:0 0 3px;font-size:15px;color:#111827;}
.card-hdr p{margin:0;font-size:12px;color:#6b7280;}
.card-body{padding:24px;}

/* ── Form ── */
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.form-group{margin-bottom:16px;}
.form-group label{display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px;}
.form-control{width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:7px;font-size:13px;font-family:inherit;color:#111827;outline:none;transition:border .15s;}
.form-control:focus{border-color:#0e7490;box-shadow:0 0 0 3px rgba(14,116,144,.1);}
select.form-control{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%236b7280' stroke-width='1.5' d='m6 8 4 4 4-4'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;background-size:16px;appearance:none;}

/* ── Drop zone ── */
.drop-zone{border:2px dashed #d1d5db;border-radius:10px;padding:38px;text-align:center;cursor:pointer;transition:all .2s;background:#fafafa;}
.drop-zone:hover,.drop-zone.dragover{border-color:#0e7490;background:#f0fdfa;}
.drop-zone.file-chosen{border-color:#15803d;background:#f0fdf4;}
.dz-icon{font-size:38px;color:#9ca3af;margin-bottom:10px;}
.dz-text{font-size:13px;font-weight:700;color:#374151;margin-bottom:4px;}
.dz-sub{font-size:11px;color:#9ca3af;}
#fileInput{display:none;}

/* ── Progress / Result ── */
.progress-wrap{display:none;margin:14px 0;}
.progress-bar-outer{background:#e5e7eb;border-radius:20px;height:8px;overflow:hidden;}
.progress-bar-inner{height:8px;background:linear-gradient(90deg,#0e7490,#22d3ee);border-radius:20px;width:0%;transition:width .3s;}
.progress-label{font-size:11px;color:#6b7280;margin-top:5px;}
.result-box{display:none;padding:12px 16px;border-radius:8px;font-size:13px;font-weight:600;margin-top:14px;}
.result-ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.result-err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}

/* ── Info box ── */
.info-box{background:#f0fdfa;border:1px solid #a5f3fc;border-radius:8px;padding:12px 16px;font-size:12px;color:#155e75;margin-bottom:16px;line-height:1.6;}
.info-box strong{color:#0e7490;}

/* ── Table ── */
.table-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #f3f4f6;flex-wrap:wrap;gap:8px;}
.tbl-title{font-size:14px;font-weight:700;color:#111827;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12px;}
.data-table th{padding:10px 12px;text-align:left;background:#f9fafb;border-bottom:2px solid #e5e7eb;color:#374151;font-size:11px;text-transform:uppercase;white-space:nowrap;}
.data-table td{padding:8px 12px;border-bottom:1px solid #f3f4f6;color:#111827;vertical-align:middle;}
.data-table tr:hover td{background:#f9fafb;}
.tr{text-align:right!important;}
.tc{text-align:center!important;}

.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600;}
.badge-teal{background:#cffafe;color:#0e7490;}
.badge-green{background:#dcfce7;color:#15803d;}
.badge-orange{background:#fef3c7;color:#92400e;}
.badge-gray{background:#f3f4f6;color:#6b7280;}

/* ── Code pill ── */
.ucode{display:inline-block;background:#e0f2fe;color:#0369a1;padding:1px 7px;border-radius:5px;font-size:11px;font-weight:700;font-family:monospace;}
.no-code{color:#d1d5db;font-size:11px;}

/* ── Toast ── */
#toast{position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;display:none;z-index:9999;max-width:400px;box-shadow:0 4px 20px rgba(0,0,0,.18);}
.toast-ok{background:#155e75;color:#fff;}
.toast-err{background:#991b1b;color:#fff;}

@media(max-width:640px){.form-row{grid-template-columns:1fr;}.sum-card-val{font-size:18px;}}
</style>

<!-- Breadcrumb -->
<div class="page-wrap">
<div class="breadcrumb">
  <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
  <span class="sep">›</span>
  <span style="color:#0e7490;font-weight:700;"><i class="fa-solid fa-boxes-stacked"></i> UShop Items Import</span>
</div>

<div class="ph-row">
  <div>
    <h2 style="margin:0;font-size:19px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-boxes-stacked" style="color:#0e7490;margin-right:8px;"></i>UShop Items — Inventory Import
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">Import inventory stock balance XLS. Items are saved to <code>ushop_items</code>, opening stock to <code>ushop_stock_history</code>.</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a href="ushop_items_view.php" class="btn btn-teal"><i class="fa-solid fa-table"></i> View Items</a>
    <a href="ushop_stock_history.php" class="btn btn-green"><i class="fa-solid fa-layer-group"></i> Stock History</a>
    <a href="ushop_items_import_history.php" class="btn btn-secondary"><i class="fa-solid fa-clock-rotate-left"></i> Import History</a>
  </div>
</div>

<!-- SUMMARY CARDS -->
<div class="sum-cards">
  <div class="sum-card">
    <div class="sum-card-label"><i class="fa-solid fa-cubes" style="margin-right:4px;"></i>Total Items</div>
    <div class="sum-card-val"><?= number_format($stats['total_items'] ?? 0) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label"><i class="fa-solid fa-tag" style="margin-right:4px;"></i>With Unilever Code</div>
    <div class="sum-card-val"><?= number_format($stats['items_with_code'] ?? 0) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label"><i class="fa-solid fa-upload" style="margin-right:4px;"></i>Total Imports</div>
    <div class="sum-card-val"><?= number_format($stats['total_imports'] ?? 0) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label"><i class="fa-solid fa-warehouse" style="margin-right:4px;"></i>Opening Stock Qty</div>
    <div class="sum-card-val"><?= number_format($stats['opening_stock_qty'] ?? 0) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label"><i class="fa-solid fa-clock" style="margin-right:4px;"></i>Last Import</div>
    <div class="sum-card-val" style="font-size:13px;padding-top:5px;">
      <?= ($stats['last_import'] ?? null) ? date('d M Y H:i', strtotime($stats['last_import'])) : '—' ?>
    </div>
  </div>
</div>

<!-- UPLOAD FORM -->
<div class="card">
  <div class="card-hdr">
    <h3><i class="fa-solid fa-file-arrow-up" style="color:#0e7490;margin-right:8px;"></i>Upload Inventory Stock Balance File</h3>
    <p>Upload the "INVENTORY STOCK BALANCE" report (.xlsx recommended). Unilever codes are extracted automatically from product names — from the end (e.g. AXE AMBER WOOD 80ML-<strong>3539203</strong>) or the start (e.g. <strong>3904001</strong>-CLEAR SHMP CSC 180ML).</p>
  </div>
  <div class="card-body">

    <div class="info-box">
      <strong>Supported formats:</strong> .xlsx, binary .xls (Excel 97-2003), XML-based .xls, and HTML-table .xls exports — the format is detected automatically.<br>
      <strong>Unilever Code extraction:</strong> a numeric code attached to the product name with a hyphen — at the end (<code>AXE BLACK-3444504</code> → <strong>3444504</strong>) or at the start (<code>3904001-CLEAR SHMP</code> → <strong>3904001</strong>) — is saved automatically. Opening stock quantity is recorded in stock history as an <strong>OPENING</strong> transaction.
    </div>

    <div class="form-row">
      <div class="form-group">
        <label><i class="fa-solid fa-calendar" style="margin-right:4px;"></i>Import Date <span style="color:#dc2626;">*</span></label>
        <input type="date" id="importDate" class="form-control" value="<?= date('Y-m-d') ?>">
        <div style="font-size:11px;color:#6b7280;margin-top:3px;">Date used for opening stock transactions.</div>
      </div>
      <div class="form-group">
        <label><i class="fa-solid fa-gears" style="margin-right:4px;"></i>Import Mode <span style="color:#dc2626;">*</span></label>
        <select id="importMode" class="form-control">
          <option value="upsert">Upsert — Add new + update existing items</option>
          <option value="new_only">New only — Skip if product code already exists</option>
        </select>
      </div>
    </div>

    <div class="form-group">
      <label><i class="fa-solid fa-file-excel" style="margin-right:4px;"></i>XLS / XLSX File <span style="color:#dc2626;">*</span></label>
      <div class="drop-zone" id="dropZone" onclick="document.getElementById('fileInput').click()">
        <div class="dz-icon"><i class="fa-solid fa-cloud-arrow-up" style="color:#0e7490;"></i></div>
        <div class="dz-text" id="dzText">Drop file here or click to browse</div>
        <div class="dz-sub" id="dzSub">Supports: .xls, .xlsx &nbsp;·&nbsp; Inventory Stock Balance format</div>
      </div>
      <input type="file" id="fileInput" accept=".xls,.xlsx">
    </div>

    <div class="form-group">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;text-transform:none;font-size:12.5px;">
        <input type="checkbox" id="includeZero" checked style="width:16px;height:16px;accent-color:#0e7490;cursor:pointer;">
        <span><strong>Include non-stock items</strong> — also record items with 0 quantity in stock history (as OPENING with qty 0)</span>
      </label>
    </div>

    <div class="form-group">
      <label><i class="fa-solid fa-note-sticky" style="margin-right:4px;"></i>Note (optional)</label>
      <input type="text" id="importNote" class="form-control" placeholder="e.g. June 2026 opening stock, monthly sync…">
    </div>

    <div class="progress-wrap" id="progressWrap">
      <div class="progress-bar-outer"><div class="progress-bar-inner" id="progressBar"></div></div>
      <div class="progress-label" id="progressLabel">Uploading…</div>
    </div>
    <div class="result-box" id="resultBox"></div>

    <button class="btn btn-teal" id="uploadBtn" onclick="doUpload()" style="width:100%;height:44px;font-size:14px;margin-top:6px;">
      <i class="fa-solid fa-database"></i> Import Items &amp; Record Opening Stock
    </button>
  </div>
</div>

<!-- RECENT IMPORTS TABLE -->
<div class="table-card">
  <div class="table-toolbar">
    <div class="tbl-title"><i class="fa-solid fa-clock-rotate-left" style="margin-right:6px;color:#0e7490;"></i>Recent Imports</div>
    <a href="ushop_items_import_history.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-list"></i> View All</a>
  </div>
  <div class="dt-wrap">
  <table class="data-table">
    <thead>
      <tr>
        <th>#</th>
        <th>Import Date</th>
        <th>Filename</th>
        <th class="tr">Total Rows</th>
        <th class="tr">New</th>
        <th class="tr">Updated</th>
        <th class="tr">Skipped</th>
        <th>Note</th>
        <th>Imported At</th>
        <th class="tc">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php
    $recent = mysqli_query($conn,"SELECT * FROM ushop_items_imports ORDER BY imported_at DESC LIMIT 10");
    $i = 1;
    while ($r = mysqli_fetch_assoc($recent)):
    ?>
      <tr>
        <td style="color:#9ca3af;font-size:11px;"><?= $i++ ?></td>
        <td><span class="badge badge-teal"><?= date('d M Y', strtotime($r['import_date'])) ?></span></td>
        <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11.5px;" title="<?= htmlspecialchars($r['filename']) ?>"><?= htmlspecialchars($r['filename']) ?></td>
        <td class="tr"><strong><?= number_format($r['total_rows']) ?></strong></td>
        <td class="tr"><span class="badge badge-green"><?= number_format($r['new_items']) ?></span></td>
        <td class="tr"><span class="badge badge-orange"><?= number_format($r['updated_items']) ?></span></td>
        <td class="tr"><span class="badge badge-gray"><?= number_format($r['skipped_rows']) ?></span></td>
        <td style="font-size:11.5px;color:#6b7280;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($r['note'] ?: '—') ?></td>
        <td style="font-size:11.5px;white-space:nowrap;"><?= date('d M Y H:i', strtotime($r['imported_at'])) ?></td>
        <td class="tc" style="white-space:nowrap;">
          <a href="ushop_items_view.php?import_id=<?= $r['id'] ?>" class="btn btn-teal btn-sm" title="View Items"><i class="fa-solid fa-eye"></i></a>
          <a href="ushop_stock_history.php?import_id=<?= $r['id'] ?>" class="btn btn-green btn-sm" title="View Stock"><i class="fa-solid fa-layer-group"></i></a>
          <a href="ushop_items_import_history.php?delete=<?= $r['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this import? This will also remove all stock history entries linked to it.')" title="Delete"><i class="fa-solid fa-trash"></i></a>
        </td>
      </tr>
    <?php endwhile; ?>
    <?php if ($i === 1): ?>
      <tr><td colspan="10" style="text-align:center;padding:40px;color:#9ca3af;font-size:13px;">No imports yet. Upload your first Inventory Stock Balance file above.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

</div><!-- .page-wrap -->

<div id="toast"></div>

<script>
const dropZone  = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
let selectedFile = null;

dropZone.addEventListener('dragover', e=>{e.preventDefault();dropZone.classList.add('dragover');});
dropZone.addEventListener('dragleave',()=>dropZone.classList.remove('dragover'));
dropZone.addEventListener('drop', e=>{
  e.preventDefault(); dropZone.classList.remove('dragover');
  if(e.dataTransfer.files[0]) setFile(e.dataTransfer.files[0]);
});
fileInput.addEventListener('change',()=>{ if(fileInput.files[0]) setFile(fileInput.files[0]); });

function setFile(f) {
  const ext = f.name.split('.').pop().toLowerCase();
  if(!['xls','xlsx'].includes(ext)){ showToast('Only .xls or .xlsx allowed.','err'); return; }
  selectedFile = f;
  dropZone.classList.add('file-chosen');
  document.getElementById('dzText').textContent = f.name;
  document.getElementById('dzSub').textContent  = (f.size/1024/1024).toFixed(2)+' MB  ·  Click to change';
  document.getElementById('resultBox').style.display='none';
}

function doUpload() {
  const dt   = document.getElementById('importDate').value;
  const mode = document.getElementById('importMode').value;
  if(!dt)           { showToast('Please select an Import Date.','err'); return; }
  if(!selectedFile) { showToast('Please choose an XLS file.','err'); return; }

  const fd = new FormData();
  fd.append('action','upload');
  fd.append('import_date', dt);
  fd.append('mode', mode);
  fd.append('note', document.getElementById('importNote').value);
  fd.append('include_zero', document.getElementById('includeZero').checked ? '1' : '0');
  fd.append('xls_file', selectedFile);

  document.getElementById('progressWrap').style.display='block';
  document.getElementById('uploadBtn').disabled=true;
  document.getElementById('uploadBtn').innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Processing…';

  const xhr = new XMLHttpRequest();
  xhr.open('POST','ushop_items_import.php');

  xhr.upload.onprogress = e => {
    if(e.lengthComputable){
      const p = Math.round(e.loaded/e.total*100);
      document.getElementById('progressBar').style.width   = p+'%';
      document.getElementById('progressLabel').textContent = 'Uploading… '+p+'%';
    }
  };

  xhr.onload = () => {
    document.getElementById('progressLabel').textContent='Processing rows…';
    try {
      const res = JSON.parse(xhr.responseText);
      const box = document.getElementById('resultBox');
      box.style.display='block';
      if(res.ok){
        box.className='result-box result-ok';
        box.innerHTML='<i class="fa-solid fa-circle-check"></i> '+res.msg;
        document.getElementById('progressBar').style.width='100%';
        showToast(res.msg,'ok');
        setTimeout(()=>location.reload(),1800);
      } else {
        box.className='result-box result-err';
        box.innerHTML='<i class="fa-solid fa-circle-xmark"></i> '+res.msg;
        showToast(res.msg,'err');
      }
    } catch(e){
      document.getElementById('resultBox').className='result-box result-err';
      document.getElementById('resultBox').innerHTML='<i class="fa-solid fa-circle-xmark"></i> Unexpected server error.';
      document.getElementById('resultBox').style.display='block';
    }
    document.getElementById('uploadBtn').disabled=false;
    document.getElementById('uploadBtn').innerHTML='<i class="fa-solid fa-database"></i> Import Items &amp; Record Opening Stock';
  };
  xhr.onerror=()=>{
    showToast('Network error. Please try again.','err');
    document.getElementById('uploadBtn').disabled=false;
    document.getElementById('uploadBtn').innerHTML='<i class="fa-solid fa-database"></i> Import Items &amp; Record Opening Stock';
  };
  xhr.send(fd);
}

function showToast(msg,type){
  const t=document.getElementById('toast');
  t.className=type==='ok'?'toast-ok':'toast-err';
  t.textContent=msg;t.style.display='block';t.style.opacity='1';
  clearTimeout(t._t);
  t._t=setTimeout(()=>{t.style.opacity='0';setTimeout(()=>t.style.display='none',300);},3500);
}
</script>

<?php include 'footer.php'; ?>
</body>
</html>
<?php
/**
 * xlsx_lite.php
 * ------------------------------------------------------------------
 * Minimal, dependency-free .xlsx reader (uses only ZipArchive + SimpleXML,
 * both bundled with standard PHP — no Composer / PhpSpreadsheet needed).
 *
 * Provides:
 *   xlsx_lite_read_sheet($path)               -> raw rows keyed by [rowNum][ColLetter] = value
 *   parse_stock_adjustment_excel($path)        -> LeverEDGE "Stock Adjustments" rows that
 *                                                  carry a "YYYY-MM-DD Delivery Shorts/Excess"
 *                                                  remark, ready for reconciliation matching.
 * ------------------------------------------------------------------
 */

const XLSX_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

function xlsx_col_letters($ref) {
    preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
    return $m ? $m[1] : '';
}

/** Concatenate all <t> text nodes inside a <si> shared-string entry (handles rich runs). */
function xlsx_si_text($si) {
    $si->registerXPathNamespace('a', XLSX_NS);
    $texts = $si->xpath('.//a:t');
    $out = '';
    foreach ($texts as $t) $out .= (string)$t;
    return $out;
}

/**
 * Reads a worksheet into $rows[rowNumber][ColumnLetter] = stringValue
 * Only ZipArchive + SimpleXML are required (both standard PHP extensions).
 */
function xlsx_lite_read_sheet($path, $sheet_index = 1) {
    if (!class_exists('ZipArchive')) {
        throw new Exception('PHP ZipArchive extension is required to read .xlsx files.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new Exception('Could not open the uploaded file as a valid .xlsx (zip) archive.');
    }

    // ── Shared strings table ─────────────────────────────────────
    $shared = [];
    $ssRaw = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssRaw !== false) {
        $ssXml = simplexml_load_string($ssRaw);
        if ($ssXml !== false) {
            $ssXml->registerXPathNamespace('a', XLSX_NS);
            foreach ($ssXml->xpath('//a:si') as $si) {
                $shared[] = xlsx_si_text($si);
            }
        }
    }

    // ── Locate the worksheet XML (default: first sheet) ─────────────
    $sheetPath = 'xl/worksheets/sheet' . intval($sheet_index) . '.xml';
    $sheetRaw = $zip->getFromName($sheetPath);
    if ($sheetRaw === false) {
        // Fallback: grab any worksheet present in the archive
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                $sheetRaw = $zip->getFromName($name);
                break;
            }
        }
    }
    $zip->close();

    if ($sheetRaw === false) {
        throw new Exception('No worksheet found inside the uploaded .xlsx file.');
    }

    $xml = simplexml_load_string($sheetRaw);
    if ($xml === false) {
        throw new Exception('Could not parse worksheet XML.');
    }
    $xml->registerXPathNamespace('a', XLSX_NS);

    $rows = [];
    foreach ($xml->xpath('//a:row') as $row) {
        $rNum = intval((string)$row['r']);
        if (!$rNum) continue;
        // On PHP 8, a namespace registered on the root SimpleXMLElement does NOT
        // carry over to xpath() calls made on child nodes returned by an earlier
        // xpath() call — it must be re-registered on each node. Without this line
        // $row->xpath('a:c') silently returns an empty array for every row.
        $row->registerXPathNamespace('a', XLSX_NS);
        foreach ($row->xpath('a:c') as $c) {
            $ref = (string)$c['r'];
            $col = xlsx_col_letters($ref);
            if ($col === '') continue;

            $c->registerXPathNamespace('a', XLSX_NS);
            $type = (string)$c['t'];
            $value = '';

            if ($type === 'inlineStr') {
                $isNode = $c->xpath('a:is');
                if (!empty($isNode)) $value = xlsx_si_text($isNode[0]);
            } elseif ($type === 's') {
                $vNode = $c->xpath('a:v');
                $idx = !empty($vNode) ? intval((string)$vNode[0]) : -1;
                $value = isset($shared[$idx]) ? $shared[$idx] : '';
            } else {
                $vNode = $c->xpath('a:v');
                $value = !empty($vNode) ? (string)$vNode[0] : '';
            }
            $rows[$rNum][$col] = $value;
        }
    }
    return $rows;
}

/**
 * Parses a LeverEDGE "Stock Adjustments" export and extracts only the rows
 * that are delivery-shortage/excess adjustments, ready for reconciliation
 * matching against unloading_data.short_excess.
 *
 * LeverEDGE records a short/excess adjustment as TWO signals on the same row:
 *   1. Transaction column (H) — the authoritative type:
 *        "Stock Subtraction" -> short   (removes stock)
 *        "Opening Stock"     -> excess  (adds stock)
 *      Other transaction types (Market Return, UDB-Sales Returns, etc.) are
 *      unrelated adjustments and are ignored.
 *   2. Remarks column (Q) — carries the delivery date the adjustment relates
 *      to, e.g. "  2026-07-25 Delivery Shorts  " / "  2026-07-28 Delivery Excess  ".
 *      This is where we pull delivery_date from; the short/excess type itself
 *      is taken from the Transaction column since that's the controlled field.
 *
 * Relevant columns (by letter, matching LeverEDGE's fixed layout):
 *   D = SKU Code, E = Item Name, H = Transaction, K = Qty Units, Q = Remarks
 *
 * Returns array of:
 *   ['delivery_date'=>'Y-m-d','type'=>'short'|'excess',
 *    'sku_code'=>'...','sku_desc'=>'...','qty'=>float,'qty_signed'=>float]
 */
function parse_stock_adjustment_excel($path) {
    $rows = xlsx_lite_read_sheet($path);
    $out = [];

    foreach ($rows as $rNum => $cols) {
        $transaction = trim($cols['H'] ?? '');
        if ($transaction === '') continue;

        $is_subtract = (stripos($transaction, 'subtract') !== false); // "Stock Subtraction" -> short
        $is_opening  = (stripos($transaction, 'opening')  !== false); // "Opening Stock"     -> excess
        if (!$is_subtract && !$is_opening) continue; // e.g. Market Return, UDB-Sales Returns — not a S/E row

        $remark = trim($cols['Q'] ?? '');
        if (!preg_match('/(\d{4}-\d{2}-\d{2})\s+Delivery\s+(Shorts|Excess)/i', $remark, $m)) {
            continue; // no delivery-date tag in remarks, so it can't be matched to a delivery
        }

        $date     = $m[1];
        $type     = $is_subtract ? 'short' : 'excess'; // Transaction column is the source of truth
        $sku_code = trim($cols['D'] ?? '');
        $sku_desc = trim($cols['E'] ?? '');
        $qty      = floatval($cols['K'] ?? 0);

        if ($sku_code === '' || $qty == 0) continue;

        $out[] = [
            'delivery_date' => $date,
            'type'          => $type,
            'sku_code'      => $sku_code,
            'sku_desc'      => $sku_desc,
            'qty'           => abs($qty),
            'qty_signed'    => $type === 'short' ? -abs($qty) : abs($qty),
        ];
    }
    return $out;
}

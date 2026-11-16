<?php
/** analysis_xlsx_lib.php — streaming .xlsx reader shared by the Analysis import pages (XMLReader, low memory). */
if (!function_exists('as_each_row')) {
/* ─────────── streaming xlsx reader ─────────── */
function as_col_idx($ref) { $n = 0; for ($i = 0, $l = strlen($ref); $i < $l; $i++) { $c = ord($ref[$i]); if ($c < 65 || $c > 90) break; $n = $n * 26 + ($c - 64); } return $n - 1; }
function as_shared_strings($zipPath) {
    $out = []; $z = new XMLReader();
    if (!@$z->open('zip://' . $zipPath . '#xl/sharedStrings.xml')) return $out;
    while ($z->read()) {
        if ($z->nodeType === XMLReader::ELEMENT && $z->name === 'si') {
            $t = ''; $depth = $z->depth;
            if (!$z->isEmptyElement) while ($z->read() && !($z->nodeType === XMLReader::END_ELEMENT && $z->name === 'si' && $z->depth === $depth)) {
                if ($z->nodeType === XMLReader::ELEMENT && $z->name === 't') $t .= $z->readString();
            }
            $out[] = $t;
        }
    }
    $z->close(); return $out;
}
/** [sheet name, path inside zip] of the FIRST sheet */
function as_first_sheet($zipPath) {
    $za = new ZipArchive(); if ($za->open($zipPath) !== true) return null;
    $wb = $za->getFromName('xl/workbook.xml'); $rels = $za->getFromName('xl/_rels/workbook.xml.rels'); $za->close();
    if (!$wb) return null;
    $name = 'Sheet1'; $rid = ''; $x = @simplexml_load_string($wb);
    if ($x) { $x->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'); $sh = $x->xpath('//m:sheets/m:sheet'); if ($sh) { $name = (string)$sh[0]['name']; $a = $sh[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships'); $rid = (string)$a['id']; } }
    $path = 'xl/worksheets/sheet1.xml';
    if ($rid !== '' && $rels && ($r = @simplexml_load_string($rels))) foreach ($r->Relationship as $rel) if ((string)$rel['Id'] === $rid) { $t = (string)$rel['Target']; $path = ltrim(strpos($t, '/') === 0 ? $t : 'xl/' . $t, '/'); break; }
    return [$name, $path];
}
/** calls $cb($rowNumber, array colIndex => value) for every row of the sheet */
function as_each_row($zipPath, $sheetPath, array $shared, callable $cb) {
    $z = new XMLReader(); if (!@$z->open('zip://' . $zipPath . '#' . $sheetPath)) return false;
    $doc = new DOMDocument();
    while ($z->read()) {
        if ($z->nodeType !== XMLReader::ELEMENT || $z->name !== 'row') continue;
        $rn = (int)$z->getAttribute('r'); $cells = [];
        $node = $z->expand($doc);
        foreach ($node->childNodes as $c) {
            if ($c->nodeName !== 'c') continue;
            $ci = as_col_idx($c->getAttribute('r')); $t = $c->getAttribute('t'); $val = null;
            if ($t === 'inlineStr') { $val = $c->textContent; }
            else { $vn = null; foreach ($c->childNodes as $k) if ($k->nodeName === 'v') { $vn = $k->textContent; break; }
                if ($vn !== null) $val = $t === 's' ? ($shared[(int)$vn] ?? '') : $vn; }
            if ($val !== null && $val !== '') $cells[$ci] = $val;
        }
        if ($cb($rn, $cells) === false) break;
    }
    $z->close(); return true;
}
function as_num($v) { $v = trim(str_replace([',', ' '], '', (string)$v)); return ($v !== '' && is_numeric($v)) ? (float)$v : 0.0; }
function as_date($v) {
    $v = trim((string)$v); if ($v === '') return null;
    if (is_numeric($v) && (float)$v > 20000) return date('Y-m-d', (int)round(((float)$v - 25569) * 86400));
    foreach (['d/m/Y', 'Y-m-d', 'd-m-Y', 'd-M-Y', 'd M Y'] as $f) { $d = DateTime::createFromFormat($f, $v); if ($d && $d->format($f) === $v) return $d->format('Y-m-d'); }
    $t = strtotime($v); return $t ? date('Y-m-d', $t) : null;
}
function as_norm($s) { return preg_replace('/[^a-z0-9]+/', '', strtolower((string)$s)); }

}

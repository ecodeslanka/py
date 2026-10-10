<?php
/**
 * SimpleXLSX - Pure PHP XLSX reader
 * License: MIT  |  https://github.com/shuchkin/simplexlsx
 * Trimmed & adapted for stock upload use.
 */
class SimpleXLSX {
    public $sheets  = [];
    public $sheets_names = [];
    private $zip;
    private $sharedStrings = [];
    private $styles = [];
    private $numFmts = [];
    private $dateFormats = [];

    public static function parse($filename) {
        $x = new self();
        return $x->_parse($filename) ? $x : false;
    }
    public static function parseError() { return ''; }

    private function _parse($filename) {
        $this->zip = new ZipArchive();
        if ($this->zip->open($filename) !== true) return false;

        // Shared strings
        $ss = $this->_zipEntry('xl/sharedStrings.xml');
        if ($ss) {
            $xml = @simplexml_load_string($ss, 'SimpleXMLElement', LIBXML_NOENT | LIBXML_NOERROR);
            if ($xml) {
                foreach ($xml->si as $si) {
                    if (isset($si->t)) {
                        $this->sharedStrings[] = (string)$si->t;
                    } else {
                        $str = '';
                        foreach ($si->r as $r) { if (isset($r->t)) $str .= (string)$r->t; }
                        $this->sharedStrings[] = $str;
                    }
                }
            }
        }

        // Styles (date detection)
        $stylesXml = $this->_zipEntry('xl/styles.xml');
        if ($stylesXml) {
            $xml = @simplexml_load_string($stylesXml, 'SimpleXMLElement', LIBXML_NOENT | LIBXML_NOERROR);
            if ($xml) {
                $builtinDateFmts = [14,15,16,17,18,19,20,21,22,27,28,29,30,31,32,33,34,35,36,
                    45,46,47,57,58,164,165,166,167,168,169,170,171,172,173,174,175,176,177,178,179,180,181,182,183,184,185,186,187,188,189,190,191,192,193,194,195,196,197,198,199,200,201,202,203,204,205,206,207,208,209];
                if (isset($xml->numFmts)) {
                    foreach ($xml->numFmts->numFmt as $nf) {
                        $id  = (int)$nf['numFmtId'];
                        $fmt = (string)$nf['formatCode'];
                        if (preg_match('/[yYmMdDhHsS]/', $fmt) && !preg_match('/\[Red\]/i', $fmt)) {
                            $this->dateFormats[$id] = true;
                        }
                    }
                }
                if (isset($xml->cellXfs)) {
                    foreach ($xml->cellXfs->xf as $xf) {
                        $numFmtId = (int)$xf['numFmtId'];
                        $this->styles[] = (in_array($numFmtId, $builtinDateFmts) || isset($this->dateFormats[$numFmtId]));
                    }
                }
            }
        }

        // Sheet list
        $wb = $this->_zipEntry('xl/workbook.xml');
        if (!$wb) return false;
        $xml = @simplexml_load_string($wb, 'SimpleXMLElement', LIBXML_NOENT | LIBXML_NOERROR);
        if (!$xml) return false;

        $sheetIdx = 0;
        foreach ($xml->sheets->sheet as $sheet) {
            $name = (string)$sheet['name'];
            $rid  = (string)$sheet['r:id'];
            $this->sheets_names[] = $name;

            // Resolve rid -> sheet file
            $relsXml = $this->_zipEntry('xl/_rels/workbook.xml.rels');
            $sheetFile = 'sheet' . ($sheetIdx + 1);
            if ($relsXml) {
                $rxml = @simplexml_load_string($relsXml, 'SimpleXMLElement', LIBXML_NOENT | LIBXML_NOERROR);
                if ($rxml) {
                    foreach ($rxml->Relationship as $rel) {
                        if ((string)$rel['Id'] === $rid) {
                            $target = (string)$rel['Target'];
                            $sheetFile = basename($target, '.xml');
                            break;
                        }
                    }
                }
            }

            $sheetXml = $this->_zipEntry('xl/worksheets/' . $sheetFile . '.xml');
            $this->sheets[$sheetIdx] = $sheetXml ? $this->_parseSheet($sheetXml) : [];
            $sheetIdx++;
        }

        $this->zip->close();
        return true;
    }

    private function _parseSheet($xml_str) {
        $xml = @simplexml_load_string($xml_str, 'SimpleXMLElement', LIBXML_NOENT | LIBXML_NOERROR);
        if (!$xml) return [];

        $rows = [];
        if (!isset($xml->sheetData->row)) return [];

        foreach ($xml->sheetData->row as $row) {
            $rowNum = (int)$row['r'] - 1;
            $rowData = [];
            foreach ($row->c as $c) {
                $ref   = (string)$c['r'];
                $col   = $this->_col($ref);
                $type  = (string)$c['t'];
                $style = isset($c['s']) ? (int)$c['s'] : -1;
                $val   = isset($c->v) ? (string)$c->v : '';

                if ($type === 's') {
                    $val = isset($this->sharedStrings[(int)$val]) ? $this->sharedStrings[(int)$val] : '';
                } elseif ($type === 'b') {
                    $val = $val ? 'TRUE' : 'FALSE';
                } elseif ($type === 'inlineStr') {
                    $val = isset($c->is->t) ? (string)$c->is->t : '';
                } elseif ($val !== '' && $style >= 0 && isset($this->styles[$style]) && $this->styles[$style]) {
                    // Date serial → Y-m-d
                    $val = $this->_excelDate((float)$val);
                }
                $rowData[$col] = $val;
            }
            if ($rowData) {
                // Fill sparse columns
                if ($rowData) {
                    $max = max(array_keys($rowData));
                    $dense = [];
                    for ($i = 0; $i <= $max; $i++) {
                        $dense[$i] = $rowData[$i] ?? '';
                    }
                    $rows[$rowNum] = $dense;
                }
            }
        }
        return $rows;
    }

    private function _col($ref) {
        preg_match('/([A-Z]+)(\d+)/', strtoupper($ref), $m);
        $col = 0;
        foreach (str_split($m[1]) as $ch) {
            $col = $col * 26 + (ord($ch) - 64);
        }
        return $col - 1;
    }

    private function _excelDate($serial) {
        // Excel date serial to Y-m-d
        if ($serial > 59) $serial--; // Lotus 1900 bug
        $unix = ($serial - 25569) * 86400;
        return date('Y-m-d', (int)$unix);
    }

    private function _zipEntry($name) {
        $data = @$this->zip->getFromName($name);
        return $data !== false ? $data : null;
    }

    public function rows($sheetIndex = 0) {
        if (!isset($this->sheets[$sheetIndex])) return [];
        $sheet = $this->sheets[$sheetIndex];
        if (!$sheet) return [];
        $maxRow = max(array_keys($sheet));
        $out = [];
        for ($i = 0; $i <= $maxRow; $i++) {
            $out[] = $sheet[$i] ?? [];
        }
        return $out;
    }
}

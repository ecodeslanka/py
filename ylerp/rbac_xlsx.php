<?php
/** rbac_xlsx.php — tiny dependency-free .xlsx writer (ZipArchive only). Used by role_export.php and user_role_report.php */
if (class_exists('MiniXlsx')) return;
class MiniSheet {
    public $name; public $cells = []; public $merges = []; public $widths = []; public $heights = [];
    public $freeze = null; public $filter = null; public $maxR = 0; public $maxC = 0;
    function __construct($n) { $this->name = $n; }
    function set($r, $c, $v, $st = []) { $this->cells[$r][$c] = [$v, $st]; $this->maxR = max($this->maxR, $r); $this->maxC = max($this->maxC, $c); }
    function style($r, $c, $st) { if (!isset($this->cells[$r][$c])) $this->set($r, $c, '', $st); else $this->cells[$r][$c][1] = $st; }
    function merge($r1, $c1, $r2, $c2) { $this->merges[] = [$r1, $c1, $r2, $c2]; }
}
class MiniXlsx {
    public $sheets = []; private $xf = ['[]' => 0]; private $xfList = [[]];
    function sheet($n) { return $this->sheets[] = new MiniSheet($n); }
    static function col($i) { $s = ''; while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); } return $s; }
    static function esc($t) { return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$t), ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
    private function sid($st) { ksort($st); $k = json_encode($st); if (!isset($this->xf[$k])) { $this->xf[$k] = count($this->xfList); $this->xfList[] = $st; } return $this->xf[$k]; }
    private function stylesXml() {
        $fonts = ['<font><sz val="10"/><name val="Calibri"/></font>']; $fills = ['<fill><patternFill patternType="none"/></fill>', '<fill><patternFill patternType="gray125"/></fill>'];
        $fi = []; $fl = []; $xfs = [];
        foreach ($this->xfList as $st) {
            $f = 0; $fill = 0; $b = 0;
            if (array_intersect_key($st, ['b' => 1, 'i' => 1, 'sz' => 1, 'color' => 1])) {
                $k = json_encode([$st['b'] ?? 0, $st['i'] ?? 0, $st['sz'] ?? 10, $st['color'] ?? '']);
                if (!isset($fi[$k])) { $fi[$k] = count($fonts);
                    $fonts[] = '<font>' . (!empty($st['b']) ? '<b/>' : '') . (!empty($st['i']) ? '<i/>' : '') . '<sz val="' . ($st['sz'] ?? 10) . '"/>'
                        . (!empty($st['color']) ? '<color rgb="FF' . $st['color'] . '"/>' : '') . '<name val="Calibri"/></font>'; }
                $f = $fi[$k];
            }
            if (!empty($st['bg'])) { if (!isset($fl[$st['bg']])) { $fl[$st['bg']] = count($fills);
                $fills[] = '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $st['bg'] . '"/><bgColor indexed="64"/></patternFill></fill>'; } $fill = $fl[$st['bg']]; }
            if (!empty($st['border'])) $b = 1;
            $al = '';
            if (isset($st['h']) || isset($st['v']) || !empty($st['wrap']) || !empty($st['indent']))
                $al = '<alignment' . (isset($st['h']) ? ' horizontal="' . $st['h'] . '"' : '') . ' vertical="' . ($st['v'] ?? 'center') . '"'
                    . (!empty($st['wrap']) ? ' wrapText="1"' : '') . (!empty($st['indent']) ? ' indent="' . $st['indent'] . '"' : '') . '/>';
            $nf = isset($st['nf']) ? (int)$st['nf'] : (!empty($st['pct']) ? 9 : 0);      // nf: 3 = #,##0   4 = #,##0.00   9 = 0%
            $xfs[] = '<xf numFmtId="' . $nf . '" fontId="' . $f . '" fillId="' . $fill . '" borderId="' . $b . '" xfId="0" applyFont="1" applyFill="1" applyBorder="1"'
                . ($nf ? ' applyNumberFormat="1"' : '') . ($al ? ' applyAlignment="1">' . $al . '</xf>' : '/>');
        }
        $bd = '<left style="thin"><color rgb="FFE5E7EB"/></left><right style="thin"><color rgb="FFE5E7EB"/></right><top style="thin"><color rgb="FFE5E7EB"/></top><bottom style="thin"><color rgb="FFE5E7EB"/></bottom><diagonal/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts><fills count="' . count($fills) . '">' . implode('', $fills) . '</fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border>' . $bd . '</border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($xfs) . '">' . implode('', $xfs) . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }
    private function sheetXml(MiniSheet $sh) {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $x .= '<sheetViews><sheetView workbookViewId="0" showGridLines="0"' . ($sh === $this->sheets[0] ? ' tabSelected="1"' : '') . '>';
        if ($sh->freeze) { [$fr, $fc] = $sh->freeze;
            $x .= '<pane' . ($fc ? ' xSplit="' . $fc . '"' : '') . ($fr ? ' ySplit="' . $fr . '"' : '') . ' topLeftCell="' . self::col($fc + 1) . ($fr + 1) . '" state="frozen" activePane="'
                . ($fr && $fc ? 'bottomRight' : ($fr ? 'bottomLeft' : 'topRight')) . '"/>'; }
        $x .= '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/>';
        if ($sh->widths) { $x .= '<cols>'; ksort($sh->widths); foreach ($sh->widths as $c => $w) $x .= '<col min="' . $c . '" max="' . $c . '" width="' . $w . '" customWidth="1"/>'; $x .= '</cols>'; }
        $x .= '<sheetData>'; ksort($sh->cells);
        foreach ($sh->cells as $r => $cols) {
            ksort($cols);
            $x .= '<row r="' . $r . '"' . (isset($sh->heights[$r]) ? ' ht="' . $sh->heights[$r] . '" customHeight="1"' : '') . '>';
            foreach ($cols as $c => [$v, $st]) {
                $ref = self::col($c) . $r; $sid = $this->sid($st);
                if (is_int($v) || is_float($v)) $x .= '<c r="' . $ref . '" s="' . $sid . '"><v>' . $v . '</v></c>';
                elseif ($v === '' || $v === null) $x .= '<c r="' . $ref . '" s="' . $sid . '"/>';
                else $x .= '<c r="' . $ref . '" s="' . $sid . '" t="inlineStr"><is><t xml:space="preserve">' . self::esc($v) . '</t></is></c>';
            }
            $x .= '</row>';
        }
        $x .= '</sheetData>';
        if ($sh->filter) $x .= '<autoFilter ref="' . $sh->filter . '"/>';
        if ($sh->merges) { $x .= '<mergeCells count="' . count($sh->merges) . '">';
            foreach ($sh->merges as [$r1, $c1, $r2, $c2]) $x .= '<mergeCell ref="' . self::col($c1) . $r1 . ':' . self::col($c2) . $r2 . '"/>'; $x .= '</mergeCells>'; }
        return $x . '</worksheet>';
    }
    function save($path) {
        $sheetsX = []; foreach ($this->sheets as $i => $sh) $sheetsX[$i] = $this->sheetXml($sh);   // builds style table first
        $z = new ZipArchive(); $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rel = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->sheets as $i => $sh) { $n = $i + 1;
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wb .= '<sheet name="' . self::esc($sh->name) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $rel .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            $z->addFromString('xl/worksheets/sheet' . $n . '.xml', $sheetsX[$i]); }
        $sn = count($this->sheets) + 1;
        $rel .= '<Relationship Id="rId' . $sn . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $z->addFromString('[Content_Types].xml', $ct . '</Types>');
        $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $z->addFromString('xl/workbook.xml', $wb . '</sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', $rel);
        $z->addFromString('xl/styles.xml', $this->stylesXml());
        $z->close();
    }
}


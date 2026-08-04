<?php

declare(strict_types=1);

namespace App\Support\Export;

use RuntimeException;
use ZipArchive;

/**
 * Penulis XLSX minimal (SpreadsheetML) tanpa dependensi eksternal — proyek ini sengaja tidak
 * memasang PhpSpreadsheet. Cukup untuk laporan tabel: judul, header bergrup (merge), header
 * kolom, baris data teks/angka, freeze panes, dan autofilter.
 *
 * Nilai ditulis sebagai inline string atau angka — TIDAK PERNAH sebagai rumus. Jadi teks yang
 * diawali "=", "+", "-", atau "@" tetap menjadi teks biasa dan tidak bisa dieksekusi Excel
 * (formula injection).
 */
class SimpleXlsxWriter
{
    /** Gaya sel (indeks cellXfs pada styles()). */
    private const S_DEFAULT = 0;
    private const S_TITLE   = 1;
    private const S_META    = 2;
    private const S_GROUP   = 3;
    private const S_HEADER  = 4;
    private const S_TEXT    = 5;
    private const S_NUMBER  = 6;

    /** @var array<int,string> potongan XML <row> */
    private array $rowsXml = [];

    /** @var array<int,string> referensi merge, mis. "B3:L3" */
    private array $merges = [];

    private int $rowIndex = 0;
    private int $maxCol = 1;

    /** @param  array<int,float>  $colWidths lebar kolom (1-based index → lebar) */
    public function __construct(
        private string $sheetName = 'Sheet1',
        private array $colWidths = [],
        private int $freezeRows = 0,
        private int $freezeCols = 0,
        private ?int $autoFilterRow = null,
    ) {}

    /** Kolom 1 → "A", 27 → "AA". */
    public static function colLetter(int $index): string
    {
        $s = '';
        while ($index > 0) {
            $rem = ($index - 1) % 26;
            $s = chr(65 + $rem).$s;
            $index = intdiv($index - 1 - $rem, 26);
        }

        return $s === '' ? 'A' : $s;
    }

    /**
     * Tulis satu baris.
     *
     * @param  array<int,mixed>  $cells  nilai per kolom (null = sel kosong)
     * @param  array<int,bool>|bool  $numeric  true = seluruh baris angka, atau per-kolom
     * @param  int|null  $style  paksa gaya tertentu (S_TITLE/S_GROUP/…)
     */
    public function addRow(array $cells, array|bool $numeric = false, ?int $style = null): void
    {
        $this->rowIndex++;
        $xml = '<row r="'.$this->rowIndex.'"'.($style === self::S_HEADER || $style === self::S_GROUP ? ' ht="28" customHeight="1"' : '').'>';
        $col = 0;
        foreach ($cells as $value) {
            $col++;
            $ref = self::colLetter($col).$this->rowIndex;
            $isNum = is_array($numeric) ? ! empty($numeric[$col - 1]) : $numeric;

            if ($value === null || $value === '') {
                $xml .= '<c r="'.$ref.'" s="'.($style ?? ($isNum ? self::S_NUMBER : self::S_TEXT)).'"/>';

                continue;
            }

            if ($isNum && is_numeric($value)) {
                $xml .= '<c r="'.$ref.'" s="'.($style ?? self::S_NUMBER).'"><v>'.(0 + $value).'</v></c>';

                continue;
            }

            $xml .= '<c r="'.$ref.'" s="'.($style ?? self::S_TEXT).'" t="inlineStr"><is><t xml:space="preserve">'
                .$this->esc((string) $value).'</t></is></c>';
        }
        $xml .= '</row>';

        $this->rowsXml[] = $xml;
        $this->maxCol = max($this->maxCol, $col);
    }

    public function addTitle(string $text, int $span): void
    {
        $this->addRow([$text], false, self::S_TITLE);
        if ($span > 1) {
            $this->merges[] = 'A'.$this->rowIndex.':'.self::colLetter($span).$this->rowIndex;
        }
    }

    public function addMeta(string $text, int $span): void
    {
        $this->addRow([$text], false, self::S_META);
        if ($span > 1) {
            $this->merges[] = 'A'.$this->rowIndex.':'.self::colLetter($span).$this->rowIndex;
        }
    }

    /**
     * Baris header bergrup: tiap grup dimerge selebar jumlah kolomnya.
     *
     * @param  array<int,array{label:string, span:int}>  $groups
     * @param  int  $leading  jumlah kolom di depan yang berdiri sendiri (mis. kolom "No")
     * @param  array<int,string>  $leadingLabels
     */
    public function addGroupHeader(array $groups, int $leading = 0, array $leadingLabels = []): void
    {
        $cells = [];
        for ($i = 0; $i < $leading; $i++) {
            $cells[] = $leadingLabels[$i] ?? '';
        }
        foreach ($groups as $g) {
            $cells[] = $g['label'];
            for ($i = 1; $i < max(1, (int) $g['span']); $i++) {
                $cells[] = '';
            }
        }
        $this->addRow($cells, false, self::S_GROUP);

        $col = $leading + 1;
        foreach ($groups as $g) {
            $span = max(1, (int) $g['span']);
            if ($span > 1) {
                $this->merges[] = self::colLetter($col).$this->rowIndex.':'.self::colLetter($col + $span - 1).$this->rowIndex;
            }
            $col += $span;
        }
    }

    /** @param  array<int,string>  $labels */
    public function addHeader(array $labels): void
    {
        $this->addRow($labels, false, self::S_HEADER);
    }

    /** Tulis file .xlsx ke $path. */
    public function save(string $path): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Tidak bisa membuat berkas Excel.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet());
        $zip->close();
    }

    private function esc(string $v): string
    {
        // Buang karakter kontrol yang ilegal di XML 1.0 (kecuali tab/LF/CR) agar file tidak korup.
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v) ?? $v;

        return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(): string
    {
        // Nama sheet Excel: maks 31 karakter, tanpa : \ / ? * [ ]
        $name = $this->esc(mb_substr(str_replace([':', '\\', '/', '?', '*', '[', ']'], '-', $this->sheetName), 0, 31));

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.$name.'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0"/></numFmts>'
            .'<fonts count="5">'
            .'<font><sz val="10"/><name val="Calibri"/></font>'                                   // 0 normal
            .'<font><b/><sz val="15"/><color rgb="FF1F2937"/><name val="Calibri"/></font>'        // 1 judul
            .'<font><sz val="9"/><color rgb="FF6B7280"/><name val="Calibri"/></font>'             // 2 meta
            .'<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'        // 3 grup
            .'<font><b/><sz val="10"/><color rgb="FF1F2937"/><name val="Calibri"/></font>'        // 4 header
            .'</fonts>'
            .'<fills count="4">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF2563EB"/><bgColor indexed="64"/></patternFill></fill>'  // 2 biru grup
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE5EDFB"/><bgColor indexed="64"/></patternFill></fill>'  // 3 biru muda header
            .'</fills>'
            .'<borders count="2">'
            .'<border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border><left style="thin"><color rgb="FFD1D5DB"/></left><right style="thin"><color rgb="FFD1D5DB"/></right>'
            .'<top style="thin"><color rgb="FFD1D5DB"/></top><bottom style="thin"><color rgb="FFD1D5DB"/></bottom><diagonal/></border>'
            .'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="7">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                                                                   // 0 default
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="center"/></xf>' // 1 judul
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                                      // 2 meta
            .'<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 3 grup
            .'<xf numFmtId="0" fontId="4" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' // 4 header
            .'<xf numFmtId="49" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>' // 5 teks
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf>' // 6 angka
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    private function sheet(): string
    {
        $lastCell = self::colLetter($this->maxCol).max(1, $this->rowIndex);

        $cols = '';
        if ($this->colWidths) {
            $cols = '<cols>';
            foreach ($this->colWidths as $i => $w) {
                $n = (int) $i + 1;
                $cols .= '<col min="'.$n.'" max="'.$n.'" width="'.round((float) $w, 2).'" customWidth="1"/>';
            }
            $cols .= '</cols>';
        }

        // Freeze panes: baris header (dan beberapa kolom kiri) tetap terlihat saat digulir.
        $pane = '';
        if ($this->freezeRows > 0 || $this->freezeCols > 0) {
            $topLeft = self::colLetter($this->freezeCols + 1).($this->freezeRows + 1);
            $active = $this->freezeCols > 0 ? ($this->freezeRows > 0 ? 'bottomRight' : 'topRight') : 'bottomLeft';
            $pane = '<pane'.($this->freezeCols > 0 ? ' xSplit="'.$this->freezeCols.'"' : '')
                .($this->freezeRows > 0 ? ' ySplit="'.$this->freezeRows.'"' : '')
                .' topLeftCell="'.$topLeft.'" activePane="'.$active.'" state="frozen"/>'
                .'<selection pane="'.$active.'" activeCell="'.$topLeft.'" sqref="'.$topLeft.'"/>';
        }

        // Urutan elemen WAJIB sesuai skema CT_Worksheet: … cols, sheetData, autoFilter, mergeCells …
        $autoFilter = '';
        if ($this->autoFilterRow !== null && $this->rowIndex >= $this->autoFilterRow) {
            $autoFilter = '<autoFilter ref="A'.$this->autoFilterRow.':'.self::colLetter($this->maxCol).$this->rowIndex.'"/>';
        }

        $mergeCells = '';
        if ($this->merges) {
            $mergeCells = '<mergeCells count="'.count($this->merges).'">';
            foreach ($this->merges as $m) {
                $mergeCells .= '<mergeCell ref="'.$m.'"/>';
            }
            $mergeCells .= '</mergeCells>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<dimension ref="A1:'.$lastCell.'"/>'
            .'<sheetViews><sheetView tabSelected="1" workbookViewId="0" showGridLines="0">'.$pane.'</sheetView></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>'
            .$cols
            .'<sheetData>'.implode('', $this->rowsXml).'</sheetData>'
            .$autoFilter
            .$mergeCells
            .'</worksheet>';
    }
}

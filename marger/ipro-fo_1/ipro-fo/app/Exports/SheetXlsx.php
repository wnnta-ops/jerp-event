<?php

declare(strict_types=1);

namespace App\Exports;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Penulis Microsoft Excel (.xlsx) ASLI dari baris apa adanya — tanpa dependency
 * Maatwebsite/PhpSpreadsheet. Menulis paket OOXML minimal langsung via ZipArchive.
 *
 * Kelas ini sengaja tidak tahu-menahu soal Eloquent: laporan keuangan (Neraca, Laba Rugi,
 * Neraca Lajur, Laporan GL Lanjutan) angkanya HASIL HITUNGAN, bukan hasil query yang bisa
 * di-chunk. XlsxExport — exporter berbasis query — memakai penulis yang sama, sehingga
 * hanya ada SATU tempat yang tahu cara menyusun berkas xlsx.
 *
 * Sel angka ditulis sebagai numeric (bisa langsung di-SUM di Excel), sel lain sebagai
 * inline string ber-UTF-8 sehingga karakter Indonesia aman.
 */
final class SheetXlsx
{
    /**
     * @param  array<int, array<int, mixed>>  $rows  seluruh baris, termasuk barisan header
     * @param  int  $headerCount  berapa baris pertama yang dianggap header (ditebalkan & dibekukan)
     * @param  array<int, string>  $merges  rentang merge, mis. 'A1:A2'
     */
    public function __construct(
        private readonly array $rows,
        private readonly int $headerCount = 1,
        private readonly string $sheetName = 'Report',
        private readonly array $merges = [],
    ) {}

    /** Kirim sebagai unduhan. */
    public function toResponse(string $filename): StreamedResponse
    {
        $binary = $this->binary();

        return new StreamedResponse(function () use ($binary): void {
            echo $binary;
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length'      => (string) strlen($binary),
            'Cache-Control'       => 'no-store, no-cache',
            'Pragma'              => 'no-cache',
        ]);
    }

    /** Susun seluruh paket .xlsx (zip OOXML) menjadi string biner. */
    public function binary(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet());

        $zip->close();
        $data = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $data;
    }

    private function sheet(): string
    {
        $body = '';
        foreach ($this->rows as $ri => $cells) {
            $rowNum   = $ri + 1;
            $isHeader = $ri < $this->headerCount;
            $body .= '<row r="'.$rowNum.'">';
            foreach (array_values($cells) as $ci => $val) {
                $ref   = $this->colLetter($ci).$rowNum;
                $style = $isHeader ? ' s="1"' : '';
                if (! $isHeader && (is_int($val) || is_float($val))) {
                    $body .= '<c r="'.$ref.'"'.$style.'><v>'.$this->num($val).'</v></c>';
                } else {
                    $text  = $val === null ? '' : (string) $val;
                    $body .= '<c r="'.$ref.'"'.$style.' t="inlineStr"><is><t xml:space="preserve">'.$this->esc($text).'</t></is></c>';
                }
            }
            $body .= '</row>';
        }

        // Bekukan baris header agar tetap terlihat saat digulir.
        $views = $this->headerCount > 0
            ? '<sheetViews><sheetView workbookViewId="0"><pane ySplit="'.$this->headerCount.'" topLeftCell="A'.($this->headerCount + 1).'" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            : '';

        $mergeXml = '';
        if ($this->merges !== []) {
            $mergeXml = '<mergeCells count="'.count($this->merges).'">';
            foreach ($this->merges as $ref) {
                $mergeXml .= '<mergeCell ref="'.$ref.'"/>';
            }
            $mergeXml .= '</mergeCells>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .$views
            .'<sheetData>'.$body.'</sheetData>'
            .$mergeXml
            .'</worksheet>';
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
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.$this->esc($this->sheetName).'" sheetId="1" r:id="rId1"/></sheets>'
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

    /** Style index 0 = normal, index 1 = tebal (untuk baris heading). */
    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    /** Indeks kolom 0-based → huruf kolom Excel (A, B, …, Z, AA, …). */
    private function colLetter(int $i): string
    {
        $s = '';
        $i++;
        while ($i > 0) {
            $m = ($i - 1) % 26;
            $s = chr(65 + $m).$s;
            $i = intdiv($i - 1, 26);
        }

        return $s;
    }

    private function num(int|float $v): string
    {
        if (is_int($v)) {
            return (string) $v;
        }
        $s = rtrim(rtrim(sprintf('%.4f', $v), '0'), '.');

        return ($s === '' || $s === '-') ? '0' : $s;
    }

    private function esc(string $s): string
    {
        // Buang karakter kontrol yang ilegal di XML 1.0.
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';

        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}

<?php

declare(strict_types=1);

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Base exporter ke Microsoft Excel (.xlsx) ASLI — tanpa dependency Maatwebsite/PhpSpreadsheet.
 *
 * Menulis paket OOXML minimal langsung via ZipArchive (ekstensi zip sudah dipakai
 * tooling xlsx project). Sel angka ditulis sebagai numeric (bisa di-SUM di Excel),
 * sel lain sebagai inline string ber-UTF-8 (karakter Indonesia aman).
 *
 * Subclass cukup implement: query(), headings(), map($row) — sama seperti CsvExport.
 */
abstract class XlsxExport
{
    /** @return Builder<\Illuminate\Database\Eloquent\Model> */
    abstract public function query(): Builder;

    /** @return array<int, string> */
    abstract public function headings(): array;

    /** @return array<int, mixed> */
    abstract public function map(mixed $row): array;

    /**
     * Baris-baris header (bisa lebih dari satu untuk header bertingkat/grouped).
     * Default: satu baris dari headings(). Override untuk header 2 baris.
     *
     * @return array<int, array<int, mixed>>
     */
    protected function headerRows(): array
    {
        return [$this->headings()];
    }

    /**
     * Rentang merge cell (mis. 'A1:A2', 'G1:H1') untuk header grouped. Default: tidak ada.
     *
     * @return array<int, string>
     */
    protected function merges(): array
    {
        return [];
    }

    /** Nama sheet di dalam workbook. */
    protected string $sheetName = 'Report';

    /**
     * Bangun file .xlsx dan kirim sebagai unduhan.
     */
    public function toResponse(string $filename): StreamedResponse
    {
        // Kumpulkan baris: header (bisa multi-baris) + data (chunk agar hemat memori).
        $headerRows  = $this->headerRows();
        $headerCount = count($headerRows);
        $rows        = $headerRows;
        $this->query()->chunk(500, function ($chunk) use (&$rows): void {
            foreach ($chunk as $row) {
                $rows[] = array_map(
                    fn ($v) => $v instanceof \BackedEnum ? $v->value : $v,
                    $this->map($row),
                );
            }
        });

        $binary = $this->build($rows, $headerCount);

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

    /**
     * Susun seluruh paket .xlsx (zip OOXML) menjadi string biner.
     *
     * @param array<int, array<int, mixed>> $rows
     */
    private function build(array $rows, int $headerCount): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet($rows, $headerCount));

        $zip->close();
        $data = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $data;
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function sheet(array $rows, int $headerCount): string
    {
        $body = '';
        foreach ($rows as $ri => $cells) {
            $rowNum   = $ri + 1;
            $isHeader = $ri < $headerCount;
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

        // Freeze baris header agar tetap terlihat saat scroll.
        $views = $headerCount > 0
            ? '<sheetViews><sheetView workbookViewId="0"><pane ySplit="'.$headerCount.'" topLeftCell="A'.($headerCount + 1).'" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            : '';

        // Merge cells untuk header grouped.
        $merges = $this->merges();
        $mergeXml = '';
        if ($merges !== []) {
            $mergeXml = '<mergeCells count="'.count($merges).'">';
            foreach ($merges as $ref) {
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

    /** Style index 0 = normal, index 1 = bold (untuk baris heading). */
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

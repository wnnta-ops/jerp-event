<?php

declare(strict_types=1);

namespace App\Support\Xlsx;

use RuntimeException;

/**
 * Reader/writer .xlsx (SpreadsheetML) minimal tanpa dependency, memakai ZipArchive (ext-zip).
 * Satu sheet, berorientasi teks — cukup untuk template import sederhana
 * (mengikuti pola "tanpa dependency" seperti App\Exports\CsvExport).
 */
final class SimpleXlsx
{
    /**
     * Tulis baris (array of array<scalar|null>) ke biner .xlsx.
     *
     * @param  array<int, array<int, scalar|null>>  $rows
     */
    public static function toString(array $rows): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($tmp === false) {
            throw new RuntimeException('Tidak dapat membuat file sementara xlsx.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Tidak dapat membuat arsip xlsx.');
        }

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');

        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>');

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach (array_values($rows) as $r => $cells) {
            $rowNum = $r + 1;
            $sheet .= '<row r="'.$rowNum.'">';
            $c = 0;
            foreach (array_values($cells) as $value) {
                $ref  = self::colLetter($c).$rowNum;
                $text = htmlspecialchars((string) ($value ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                // t="inlineStr" → tidak perlu sharedStrings; selalu teks (jaga leading zero & format BC-000…).
                $sheet .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.$text.'</t></is></c>';
                $c++;
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);

        $zip->close();
        $bin = file_get_contents($tmp);
        @unlink($tmp);
        if ($bin === false) {
            throw new RuntimeException('Gagal membaca xlsx sementara.');
        }

        return $bin;
    }

    /**
     * Baca worksheet pertama .xlsx → matriks string 0-index.
     *
     * @return array<int, array<int, string>>
     */
    public static function rows(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('File bukan .xlsx yang valid.');
        }

        // sharedStrings (opsional) — Excel asli memakai ini untuk teks.
        $shared = [];
        $ssXml  = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml !== false && $ssXml !== '') {
            $ss = @simplexml_load_string($ssXml);
            if ($ss !== false) {
                foreach ($ss->si as $si) {
                    $shared[] = self::siText($si);
                }
            }
        }

        // Worksheet pertama — relasi default Excel: xl/worksheets/sheet1.xml.
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetXml === false || $sheetXml === '') {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name !== false && preg_match('#^xl/worksheets/.+\.xml$#', $name)) {
                    $sheetXml = $zip->getFromName($name);
                    break;
                }
            }
        }
        $zip->close();

        if (! is_string($sheetXml) || $sheetXml === '') {
            throw new RuntimeException('Worksheet tidak ditemukan dalam file xlsx.');
        }

        $xml = @simplexml_load_string($sheetXml);
        if ($xml === false) {
            throw new RuntimeException('Worksheet xlsx tidak dapat dibaca.');
        }

        $out = [];
        foreach ($xml->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $ref  = (string) $c['r'];
                $col  = self::colIndex(preg_replace('/\d+/', '', $ref) ?: 'A');
                $type = (string) $c['t'];

                if ($type === 'inlineStr') {
                    $val = isset($c->is) ? self::siText($c->is) : '';
                } elseif ($type === 's') {
                    $idx = (int) $c->v;
                    $val = $shared[$idx] ?? '';
                } else {
                    $val = isset($c->v) ? (string) $c->v : '';
                }
                $cells[$col] = $val;
            }
            if ($cells === []) {
                $out[] = [];

                continue;
            }
            $max  = max(array_keys($cells));
            $line = [];
            for ($i = 0; $i <= $max; $i++) {
                $line[$i] = $cells[$i] ?? '';
            }
            $out[] = $line;
        }

        return $out;
    }

    private static function siText(\SimpleXMLElement $si): string
    {
        if (isset($si->t)) {
            return (string) $si->t;
        }
        $buf = '';
        foreach ($si->r as $r) {
            $buf .= (string) $r->t;
        }

        return $buf;
    }

    private static function colLetter(int $i): string
    {
        $s = '';
        $i++;
        while ($i > 0) {
            $i--;
            $s = chr(65 + ($i % 26)).$s;
            $i = intdiv($i, 26);
        }

        return $s;
    }

    private static function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }
}

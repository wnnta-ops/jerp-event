<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Contractor\Concerns;

use Illuminate\Http\Response as HttpResponse;

/**
 * Baca/tulis file .xlsx/.csv minimal tanpa dependency eksternal
 * (pola sama dengan KplController; dipisah agar bisa dipakai ulang).
 */
trait HandlesXlsx
{
    /** Bangun & unduh file .xlsx minimal (inline string). */
    protected function xlsxDownload(array $rows, string $filename, string $sheetName = 'Sheet1'): HttpResponse
    {
        $e   = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $col = function (int $i): string { $s = ''; $i++; while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m).$s; $i = intdiv($i - 1, 26); } return $s; };
        $body = '';
        foreach ($rows as $ri => $row) {
            $r = $ri + 1; $cells = '';
            foreach (array_values($row) as $ci => $val) {
                $ref = $col($ci).$r;
                if ($val === '' || $val === null) {
                    $cells .= '<c r="'.$ref.'"/>';
                } elseif (is_int($val) || is_float($val)) {
                    $cells .= '<c r="'.$ref.'"><v>'.$val.'</v></c>';
                } else {
                    $cells .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.$e($val).'</t></is></c>';
                }
            }
            $body .= '<row r="'.$r.'">'.$cells.'</row>';
        }
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$body.'</sheetData></worksheet>';
        $ct    = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>';
        $rels  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
        $wb    = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$e($sheetName).'" sheetId="1" r:id="rId1"/></sheets></workbook>';
        $wbr   = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>';

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $ct);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('xl/workbook.xml', $wb);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbr);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
        $content = (string) file_get_contents($tmp);
        @unlink($tmp);

        return response($content)
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
    }

    /** Baca file .xlsx (ZipArchive) atau .csv → array baris [ [col0, col1, ...], ... ]. */
    protected function xlsxRead(string $path): array
    {
        $sig = (string) @file_get_contents($path, false, null, 0, 2);
        if ($sig !== 'PK') { // bukan zip → perlakukan sebagai CSV
            $rows = [];
            if (($h = @fopen($path, 'r')) !== false) {
                while (($d = fgetcsv($h, 0, ',')) !== false) { $rows[] = $d; }
                fclose($h);
            }

            return $rows;
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) { return []; }
        $shared = [];
        if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            foreach (preg_split('/<si[ >]/', $ss) as $k => $si) {
                if ($k === 0 || ! str_contains($si, '</si>')) { continue; }
                preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $m);
                $shared[] = html_entity_decode(implode('', $m[1]), ENT_QUOTES | ENT_XML1);
            }
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheet === false) { return []; }

        $rows = [];
        preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $sheet, $rm);
        foreach ($rm[1] as $rowXml) {
            $cells = [];
            preg_match_all('/<c\b([^>]*?)(?:\/>|>(.*?)<\/c>)/s', $rowXml, $cm, PREG_SET_ORDER);
            foreach ($cm as $c) {
                $attr = $c[1]; $inner = $c[2] ?? '';
                $ci   = preg_match('/r="([A-Z]+)\d+"/', $attr, $rr) ? $this->colToIdx($rr[1]) : count($cells);
                $t    = preg_match('/t="([^"]+)"/', $attr, $tt) ? $tt[1] : 'n';
                $val  = '';
                if ($t === 's' && preg_match('/<v>(.*?)<\/v>/s', $inner, $vv)) {
                    $val = $shared[(int) $vv[1]] ?? '';
                } elseif ($t === 'inlineStr' && preg_match('/<t[^>]*>(.*?)<\/t>/s', $inner, $vv)) {
                    $val = html_entity_decode($vv[1], ENT_QUOTES | ENT_XML1);
                } elseif (preg_match('/<v>(.*?)<\/v>/s', $inner, $vv)) {
                    $val = $vv[1];
                }
                $cells[$ci] = $val;
            }
            if ($cells !== []) {
                ksort($cells);
                $line = [];
                for ($i = 0, $max = max(array_keys($cells)); $i <= $max; $i++) { $line[$i] = $cells[$i] ?? ''; }
                $rows[] = $line;
            }
        }

        return $rows;
    }

    protected function colToIdx(string $col): int
    {
        $n = 0;
        foreach (str_split($col) as $ch) { $n = $n * 26 + (ord($ch) - 64); }

        return $n - 1;
    }
}

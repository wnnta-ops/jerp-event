<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Penulis & pembaca XLSX seadanya — satu lembar, tanpa gaya, tanpa rumus.
 *
 * ── KENAPA DITULIS SENDIRI, BUKAN MEMAKAI PUSTAKA ──
 *
 * BO tidak punya pustaka Excel, dan menambahkannya berarti mengubah composer.json tanpa bisa
 * memperbarui composer.lock dari lingkungan ini — `composer install --no-dev` saat deploy akan
 * GAGAL karena lock tidak sinkron, dan seluruh naik-versi berhenti di situ. Yang dibutuhkan
 * pun sempit: satu lembar berisi header dan angka. `ext-zip` dan `simplexml` sudah ada.
 *
 * Yang SENGAJA tidak didukung: beberapa lembar, gaya, rumus, tanggal ber-format. Bila kelak
 * butuh itu, pakailah pustaka sungguhan — jangan menumbuhkan kelas ini.
 */
final class Xlsx
{
    /**
     * Rakit satu berkas XLSX dan pulangkan isinya sebagai untai biner.
     *
     * Memakai inlineStr, bukan sharedStrings: berkas sedikit lebih besar, tetapi tidak perlu
     * tabel string terpisah yang harus konsisten dengan tiap selnya — dan template ini hanya
     * belasan baris.
     *
     * @param  list<string>  $header
     * @param  list<list<string|int|float|null>>  $baris
     */
    public static function tulis(array $header, array $baris, string $namaLembar = 'Sheet1'): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        $semua = array_merge([$header], $baris);
        foreach ($semua as $i => $row) {
            $nomor = $i + 1;
            $xml .= '<row r="'.$nomor.'">';
            foreach (array_values($row) as $k => $nilai) {
                $ref = self::kolom($k + 1).$nomor;
                if ($nilai === null || $nilai === '') {
                    continue; // sel kosong tidak perlu ditulis
                }
                if (is_int($nilai) || is_float($nilai)) {
                    $xml .= '<c r="'.$ref.'"><v>'.$nilai.'</v></c>';
                } else {
                    $xml .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'
                        .htmlspecialchars((string) $nilai, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';

        $berkas = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($berkas === false) {
            throw new RuntimeException('Tidak bisa membuat berkas sementara untuk XLSX.');
        }

        $zip = new ZipArchive;
        if ($zip->open($berkas, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Tidak bisa merakit berkas XLSX.');
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
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
            .'<sheet name="'.htmlspecialchars($namaLembar, ENT_XML1 | ENT_QUOTES, 'UTF-8').'" sheetId="1" r:id="rId1"/>'
            .'</sheets></workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>');

        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();

        $isi = (string) file_get_contents($berkas);
        @unlink($berkas);

        return $isi;
    }

    /**
     * Baca lembar pertama sebuah XLSX menjadi larik baris.
     *
     * SEL KOSONG TIDAK DILEWATI. Excel menghilangkan sel kosong dari XML-nya, jadi baris
     * dibangun dari referensi sel (A1, C1, …), bukan dari urutan kemunculan — kalau tidak,
     * satu sel kosong di tengah menggeser seluruh kolom di kanannya dan nilai bulan terbaca
     * pada bulan yang salah.
     *
     * @return list<list<string>>
     */
    public static function baca(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Berkas tidak bisa dibuka — pastikan formatnya .xlsx.');
        }

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheet === false) {
            // Nama lembar tidak selalu sheet1.xml; cari yang pertama.
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = (string) $zip->getNameIndex($i);
                if (str_starts_with($n, 'xl/worksheets/') && str_ends_with($n, '.xml')) {
                    $sheet = $zip->getFromName($n);
                    break;
                }
            }
        }

        if ($sheet === false || $sheet === null) {
            $zip->close();
            throw new RuntimeException('Lembar kerja tidak ditemukan di dalam berkas.');
        }

        // sharedStrings: Excel menyimpan hampir semua teks di sini, bukan di dalam selnya.
        $shared = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false) {
            $x = @simplexml_load_string($ss);
            if ($x !== false) {
                foreach ($x->si as $si) {
                    $shared[] = self::teksSi($si);
                }
            }
        }
        $zip->close();

        $x = @simplexml_load_string($sheet);
        if ($x === false) {
            throw new RuntimeException('Isi lembar kerja tidak terbaca.');
        }

        $hasil = [];
        foreach ($x->sheetData->row as $row) {
            $baris = [];
            $maks = 0;
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $kol = self::indeksKolom($ref);
                $t = (string) $c['t'];

                if ($t === 'inlineStr') {
                    $nilai = self::teksSi($c->is);
                } elseif ($t === 's') {
                    $nilai = $shared[(int) $c->v] ?? '';
                } else {
                    $nilai = (string) $c->v;
                }

                $baris[$kol] = $nilai;
                $maks = max($maks, $kol);
            }

            $rapi = [];
            for ($i = 1; $i <= $maks; $i++) {
                $rapi[] = $baris[$i] ?? '';
            }
            $hasil[] = $rapi;
        }

        return $hasil;
    }

    /** Gabungkan potongan teks pada <si>/<is> — Excel memecahnya jadi beberapa <r> bila berformat. */
    private static function teksSi(?SimpleXMLElement $si): string
    {
        if ($si === null) {
            return '';
        }
        if (isset($si->t)) {
            return (string) $si->t;
        }

        $teks = '';
        foreach ($si->r ?? [] as $r) {
            $teks .= (string) $r->t;
        }

        return $teks;
    }

    /** 1 → A, 27 → AA. */
    private static function kolom(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $n--;
            $s = chr(65 + $n % 26).$s;
            $n = intdiv($n, 26);
        }

        return $s;
    }

    /** "C12" → 3. */
    private static function indeksKolom(string $ref): int
    {
        preg_match('/^([A-Z]+)/', strtoupper($ref), $m);
        $huruf = $m[1] ?? 'A';
        $n = 0;
        foreach (str_split($huruf) as $h) {
            $n = $n * 26 + (ord($h) - 64);
        }

        return $n;
    }
}

<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Support\Xlsx\SimpleXlsx;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Pembaca berkas import massal — .xlsx / .xls / .csv → matriks + pencocokan kolom.
 *
 * Dikeluarkan jadi kelas tersendiri karena bagian INI-lah yang identik di setiap layar import
 * (Saldo Awal Hutang, Supplier, Karyawan), sedangkan aturan per barisnya memang berbeda-beda.
 * Menyalinnya ulang berarti tiap salinan punya jebakan sendiri: yang satu menangani BOM, yang
 * lain tidak; yang satu mengenali serial tanggal Excel, yang lain membacanya sebagai angka.
 */
final class TabelImpor
{
    /**
     * Baca berkas jadi matriks string, baris kosong dibuang.
     *
     * @return array<int,array<int,string>>
     *
     * @throws RuntimeException bila formatnya tidak dikenal atau berkasnya tidak terbaca
     */
    public static function baca(UploadedFile $file): array
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());

        if (in_array($ext, ['xlsx', 'xls'], true)) {
            $rows = SimpleXlsx::rows($file->getRealPath());
        } elseif (in_array($ext, ['csv', 'txt'], true)) {
            $rows = self::bacaCsv($file->getRealPath());
        } else {
            throw new RuntimeException('Format file harus .xlsx, .xls, atau .csv. Unduh template untuk format yang benar.');
        }

        // Baris yang SELURUH selnya kosong dibuang — Excel kerap menyimpan ratusan baris hampa
        // di bawah data, dan tanpa ini laporannya berbunyi "500 baris dilewati".
        return array_values(array_filter(
            (array) $rows,
            fn ($r) => is_array($r) && trim(implode('', array_map('strval', $r))) !== ''
        ));
    }

    /**
     * CSV dibaca lewat fgetcsv, BUKAN str_getcsv per baris.
     *
     * Alasannya bukan gaya: str_getcsv atas hasil file() memotong sel berkutip yang isinya
     * mengandung baris baru — alamat dua baris, misalnya — jadi dua baris rusak. fgetcsv
     * mengerti kutipannya.
     *
     * @return array<int,array<int,string>>
     */
    private static function bacaCsv(string $path): array
    {
        $fh = @fopen($path, 'r');
        if ($fh === false) {
            throw new RuntimeException('File tidak dapat dibuka.');
        }

        // BOM UTF-8 dari Excel. Bila tidak dibuang, kolom pertama bernama "\xEF\xBB\xBFkode"
        // dan pencocokan header meleset — header ikut terbaca sebagai data.
        $awal = fread($fh, 3);
        if ($awal !== "\xEF\xBB\xBF") {
            rewind($fh);
        }

        // Pemisah ditebak dari baris pertama: Excel berlokal Indonesia menyimpan CSV
        // ber-titik-koma, dan membacanya sebagai koma menghasilkan satu kolom raksasa.
        $posisi  = ftell($fh);
        $baris1  = (string) fgets($fh);
        $pemisah = substr_count($baris1, ';') > substr_count($baris1, ',') ? ';' : ',';
        fseek($fh, $posisi === false ? 0 : $posisi);

        $rows = [];
        while (($r = fgetcsv($fh, 0, $pemisah)) !== false) {
            $rows[] = array_map(fn ($v) => (string) $v, $r);
        }
        fclose($fh);

        return $rows;
    }

    /**
     * Cari indeks kolom dari daftar kata kunci (dicocokkan sebagai substring, huruf besar).
     *
     * DUA hal yang membuat pencocokan naif salah, dan dua-duanya salah tanpa galat:
     *
     * 1. Kata kunci diputar di lingkar LUAR, kolom di lingkar dalam. Dengan urutan sebaliknya,
     *    kunci longgar pada kolom awal mengalahkan kunci spesifik pada kolom belakangnya:
     *    judul "KODE SUPPLIER" mengandung "SUPPLIER" sehingga tertangkap sebagai kolom Nama,
     *    dan seluruh berkas terimpor dengan nama berisi kode.
     * 2. Satu kolom hanya boleh melayani SATU field ($dipakai). Tanpa itu Kode dan Nama bisa
     *    menunjuk kolom yang sama, dan keduanya tampak "ketemu".
     *
     * @param  array<int,string>  $header
     * @param  array<int,string>  $kunci     diperiksa berurutan; yang lebih spesifik ditaruh dulu
     * @param  array<int,string>  $kecuali   bila salah satu ini ikut ada, kolomnya BUKAN yang dimaksud
     * @param  array<int,bool>    $dipakai   indeks kolom yang sudah diklaim field lain
     */
    public static function kolom(array $header, array $kunci, array $kecuali = [], array $dipakai = []): ?int
    {
        $bersih = [];
        foreach ($header as $i => $h) {
            $h = strtoupper(trim((string) $h));
            if ($h === '' || isset($dipakai[$i])) {
                continue;
            }
            foreach ($kecuali as $x) {
                if (str_contains($h, strtoupper($x))) {
                    continue 2;
                }
            }
            $bersih[$i] = $h;
        }

        foreach ($kunci as $k) {
            $k = strtoupper($k);
            foreach ($bersih as $i => $h) {
                if (str_contains($h, $k)) {
                    return $i;
                }
            }
        }

        return null;
    }

    /** Header dinormalkan jadi huruf besar tanpa spasi tepi. @return array<int,string> */
    public static function header(array $baris): array
    {
        return array_map(fn ($h) => strtoupper(trim((string) $h)), $baris);
    }

    /** Nilai satu sel; indeks null → string kosong. */
    public static function sel(array $baris, ?int $i): string
    {
        return $i === null ? '' : trim((string) ($baris[$i] ?? ''));
    }

    /** Tanggal apa adanya dari Excel maupun ketikan manusia. Null = tidak terbaca. */
    public static function tanggal(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        // Serial Excel (angka murni, mis. 46184 ≈ 2026) — epoch 1899-12-30.
        if (preg_match('/^\d{4,6}$/', $raw)) {
            $serial = (int) $raw;
            if ($serial > 1 && $serial < 80000) {
                return Carbon::create(1899, 12, 30)->addDays($serial)->toDateString();
            }
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y/m/d', 'm/d/Y'] as $fmt) {
            try {
                $d = Carbon::createFromFormat('!'.$fmt, $raw);
                if ($d !== false) {
                    return $d->toDateString();
                }
            } catch (\Throwable) {
                // coba format berikutnya
            }
        }
        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Nominal dari berbagai gaya penulisan. Null = tidak terbaca.
     *
     * "1.000.000,50" (Indonesia) dan "1,000,000.50" (Inggris) dua-duanya dipakai di lapangan;
     * menebak salah satu saja membuat gaji 7 juta terbaca 7 rupiah tanpa satu pun galat.
     */
    public static function angka(string $raw): ?float
    {
        $raw = preg_replace('/[^\d.,\-]/', '', trim($raw)) ?? '';
        if ($raw === '' || $raw === '-') {
            return null;
        }
        $adaKoma = str_contains($raw, ',');
        $adaTitik = str_contains($raw, '.');
        if ($adaKoma && $adaTitik) {
            // Yang muncul TERAKHIR adalah pemisah desimalnya.
            $raw = strrpos($raw, ',') > strrpos($raw, '.')
                ? str_replace(['.', ','], ['', '.'], $raw)
                : str_replace(',', '', $raw);
        } elseif ($adaKoma) {
            // Koma tunggal: desimal ("7500,50"), kecuali grup akhir 3 digit ("7,500" = ribuan).
            $bagian = explode(',', $raw);
            $raw = (count($bagian) > 2 || strlen((string) end($bagian)) === 3)
                ? str_replace(',', '', $raw)
                : str_replace(',', '.', $raw);
        } elseif ($adaTitik) {
            $bagian = explode('.', $raw);
            if (count($bagian) > 2 || (count($bagian) === 2 && strlen((string) end($bagian)) === 3)) {
                $raw = str_replace('.', '', $raw);
            }
        }

        return is_numeric($raw) ? round((float) $raw, 2) : null;
    }

    /**
     * Ya/Tidak dari berbagai gaya. Null = kosong (pemanggil yang menentukan bawaannya).
     *
     * Kolom kosong TIDAK boleh dibaca "tidak aktif": mayoritas berkas import tidak mengisi
     * kolom status sama sekali, dan mengimpor seluruh karyawan dalam keadaan nonaktif adalah
     * kekeliruan yang tidak terlihat sampai payroll berikutnya kosong.
     */
    public static function boolean(string $raw): ?bool
    {
        $raw = strtolower(trim($raw));
        if ($raw === '') {
            return null;
        }
        if (in_array($raw, ['1', 'y', 'ya', 'yes', 'true', 'aktif', 'active', 'a'], true)) {
            return true;
        }
        if (in_array($raw, ['0', 'n', 't', 'no', 'tidak', 'false', 'nonaktif', 'non aktif', 'inactive'], true)) {
            return false;
        }

        return null;
    }
}

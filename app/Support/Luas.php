<?php

namespace App\Support;

/**
 * Tampilan angka luas (m²) untuk cetakan.
 *
 * fo_units.luas_tanah & luas_bangunan di-cast decimal:2, jadi Eloquent memulangkan
 * "105.00" — dan itulah yang selama ini tercetak di SPR, SPRS, SPR Bank, dan Faktur
 * Penjualan. Dua nol di belakang koma itu tidak berarti apa-apa untuk luas tanah, dan
 * pembaca dokumen menanyakannya.
 *
 * Nol di belakang dibuang, tetapi desimal yang BERARTI dipertahankan:
 *   105,00 → "105"      87,50 → "87,5"      105,25 → "105,25"
 *
 * Membulatkan semuanya ke bilangan bulat sempat dicoba dan dibatalkan (16 Sep 2026):
 * ada 16 unit berluas tanah 87,50 m² (kode 1.1-001.JRK-*) yang jadi tercetak 88. Luas
 * tanah dipakai menghitung harga dan ikut ditandatangani pembeli, jadi selisih setengah
 * meter itu nyata — bukan sekadar tampilan — dan akan terlihat bila SPR dibandingkan
 * dengan sertifikat.
 *
 * Nilainya sendiri TIDAK diubah: cast decimal:2 dan isi basis data tetap apa adanya,
 * karena keduanya dipakai perhitungan harga. Yang dirapikan hanya yang dicetak.
 *
 * Pemisah desimalnya koma, mengikuti penulisan Indonesia pada dokumen yang sama.
 */
class Luas
{
    public static function rapi(mixed $v, string $kosong = '—'): string
    {
        if ($v === null || $v === '' || $v === false) {
            return $kosong;
        }

        $f = (float) $v;
        if ($f == 0.0) {
            return $kosong;
        }

        // rtrim dua tahap: buang nol di belakang, lalu titik desimal bila tak bersisa.
        $s = rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.');

        return str_replace('.', ',', $s);
    }
}

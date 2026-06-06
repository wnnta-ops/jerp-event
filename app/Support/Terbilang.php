<?php

namespace App\Support;

/**
 * Konversi angka rupiah → kata bahasa Indonesia (untuk kwitansi).
 * Contoh: 2_500_000 → "Dua juta lima ratus ribu rupiah".
 */
class Terbilang
{
    public static function rupiah(float $amount): string
    {
        $n = (int) round($amount);
        $words = trim(self::words($n));
        $words = $words === '' ? 'nol' : $words;

        return ucfirst(preg_replace('/\s+/', ' ', $words)).' rupiah';
    }

    private static function words(int $n): string
    {
        $satuan = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];

        if ($n < 12) {
            return $satuan[$n];
        }
        if ($n < 20) {
            return self::words($n - 10).' belas';
        }
        if ($n < 100) {
            return self::words(intdiv($n, 10)).' puluh '.self::words($n % 10);
        }
        if ($n < 200) {
            return 'seratus '.self::words($n - 100);
        }
        if ($n < 1000) {
            return self::words(intdiv($n, 100)).' ratus '.self::words($n % 100);
        }
        if ($n < 2000) {
            return 'seribu '.self::words($n - 1000);
        }
        if ($n < 1_000_000) {
            return self::words(intdiv($n, 1000)).' ribu '.self::words($n % 1000);
        }
        if ($n < 1_000_000_000) {
            return self::words(intdiv($n, 1_000_000)).' juta '.self::words($n % 1_000_000);
        }
        if ($n < 1_000_000_000_000) {
            return self::words(intdiv($n, 1_000_000_000)).' miliar '.self::words($n % 1_000_000_000);
        }

        return self::words(intdiv($n, 1_000_000_000_000)).' triliun '.self::words($n % 1_000_000_000_000);
    }
}

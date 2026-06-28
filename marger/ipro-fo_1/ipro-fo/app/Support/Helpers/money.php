<?php

declare(strict_types=1);

if (! function_exists('format_rupiah')) {
    /**
     * Format angka menjadi string Rupiah dengan separator ribuan id-ID.
     *
     * Contoh: format_rupiah(1234567.89) -> "Rp 1.234.568"
     */
    function format_rupiah(float|int|string $amount, int $decimals = 0, bool $withSymbol = true): string
    {
        $formatted = number_format((float) $amount, $decimals, ',', '.');

        return $withSymbol ? "Rp {$formatted}" : $formatted;
    }
}

if (! function_exists('parse_rupiah')) {
    /**
     * Parse string Rupiah "Rp 1.234.568" -> 1234568.0
     */
    function parse_rupiah(string $input): float
    {
        $clean = preg_replace('/[^\d,\-]/', '', $input) ?? '';
        $clean = str_replace(',', '.', $clean);

        return (float) $clean;
    }
}

if (! function_exists('terbilang')) {
    /**
     * Konversi angka menjadi teks Bahasa Indonesia (untuk kuitansi).
     */
    function terbilang(int $number): string
    {
        $words = [
            '', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan',
            'sepuluh', 'sebelas',
        ];

        if ($number < 12) {
            return $words[$number] ?? '';
        }

        if ($number < 20) {
            return terbilang($number - 10).' belas';
        }

        if ($number < 100) {
            return terbilang(intdiv($number, 10)).' puluh'.($number % 10 ? ' '.terbilang($number % 10) : '');
        }

        if ($number < 200) {
            return 'seratus'.($number - 100 ? ' '.terbilang($number - 100) : '');
        }

        if ($number < 1000) {
            return terbilang(intdiv($number, 100)).' ratus'.($number % 100 ? ' '.terbilang($number % 100) : '');
        }

        if ($number < 2000) {
            return 'seribu'.($number - 1000 ? ' '.terbilang($number - 1000) : '');
        }

        if ($number < 1_000_000) {
            return terbilang(intdiv($number, 1000)).' ribu'.($number % 1000 ? ' '.terbilang($number % 1000) : '');
        }

        if ($number < 1_000_000_000) {
            return terbilang(intdiv($number, 1_000_000)).' juta'.($number % 1_000_000 ? ' '.terbilang($number % 1_000_000) : '');
        }

        if ($number < 1_000_000_000_000) {
            return terbilang(intdiv($number, 1_000_000_000)).' miliar'.($number % 1_000_000_000 ? ' '.terbilang($number % 1_000_000_000) : '');
        }

        return terbilang(intdiv($number, 1_000_000_000_000)).' triliun'.($number % 1_000_000_000_000 ? ' '.terbilang($number % 1_000_000_000_000) : '');
    }
}

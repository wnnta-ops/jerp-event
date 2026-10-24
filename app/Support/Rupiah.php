<?php

namespace App\Support;

/**
 * Util nilai Rupiah. Dipakai macro TextInput::rupiah() (format ribuan) & perhitungan
 * yang membaca state form secara live (mis. ringkasan selisih Jadwal Tagihan).
 */
class Rupiah
{
    /**
     * Parse string/number Rp ke float — tahan dua bentuk:
     *  - money mask gaya Indonesia (488.600.000) → titik = pemisah ribuan
     *  - nilai mentah dari DB (6700000.00 / 25000000.0000) → titik akhir + 1-2 ATAU ≥4 digit =
     *    desimal (dibuang). Mask ribuan selalu tepat 3 digit sesudah titik, jadi tidak tertukar.
     *    24 Okt 2026: kolom decimal(19,4) (tarif Skema BLT/Komisi) dulu terbaca ×10.000.
     */
    public static function toFloat(mixed $v): float
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '') {
            return 0.0;
        }
        $neg = str_starts_with($s, '-');
        // Titik terakhir diikuti 1-2 atau ≥4 digit = desimal (nilai Rp bulat → dibuang).
        if (preg_match('/\.(\d{1,2}|\d{4,})$/', $s)) {
            $s = substr($s, 0, (int) strrpos($s, '.'));
        }
        $int = preg_replace('/\D/', '', $s);

        return ($neg ? -1 : 1) * (float) ($int === '' ? '0' : $int);
    }

    /** Untuk formatStateUsing: nilai tersimpan → string bilangan bulat (mask yang memformat tampilan). */
    public static function formatState(mixed $state): ?string
    {
        return ($state === null || $state === '') ? null : (string) (int) self::toFloat($state);
    }
}

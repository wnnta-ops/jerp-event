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
     *  - nilai mentah dari DB (6700000.00) → titik akhir + 1-2 digit = desimal (dibuang)
     */
    public static function toFloat(mixed $v): float
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '') {
            return 0.0;
        }
        $neg = str_starts_with($s, '-');
        // Titik terakhir diikuti tepat 1-2 digit = desimal (nilai Rp bulat → dibuang).
        if (preg_match('/\.\d{1,2}$/', $s)) {
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

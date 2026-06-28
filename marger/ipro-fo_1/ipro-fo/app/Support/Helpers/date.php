<?php

declare(strict_types=1);

use Carbon\Carbon;

if (! function_exists('jakarta')) {
    /**
     * Convert Carbon/string ke Carbon Asia/Jakarta.
     */
    function jakarta(Carbon|string|null $value = null): Carbon
    {
        if ($value === null) {
            return Carbon::now('Asia/Jakarta');
        }
        if (is_string($value)) {
            $value = Carbon::parse($value);
        }

        return $value->copy()->setTimezone('Asia/Jakarta');
    }
}

if (! function_exists('format_tanggal')) {
    /**
     * Format Carbon menjadi tanggal Indonesia: "27 Mei 2026".
     */
    function format_tanggal(Carbon|string|null $value): string
    {
        if ($value === null) {
            return '-';
        }

        return jakarta($value)->locale('id')->isoFormat('D MMMM YYYY');
    }
}

if (! function_exists('format_tanggal_jam')) {
    function format_tanggal_jam(Carbon|string|null $value): string
    {
        if ($value === null) {
            return '-';
        }

        return jakarta($value)->locale('id')->isoFormat('D MMM YYYY, HH:mm');
    }
}

if (! function_exists('hari_sampai')) {
    /**
     * Hitung selisih hari dari sekarang ke tanggal target (positif: future, negatif: past).
     */
    function hari_sampai(Carbon|string $target): int
    {
        return (int) jakarta()->startOfDay()->diffInDays(jakarta($target)->startOfDay(), false);
    }
}

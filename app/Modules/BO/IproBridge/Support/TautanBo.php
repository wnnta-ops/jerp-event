<?php

declare(strict_types=1);

namespace App\Modules\BO\IproBridge\Support;

use Filament\Notifications\Actions\Action;

/**
 * Tautan dari FO ke halaman tertentu di BO — lewat SSO (/sso/to-bo?next=...), jadi pengguna
 * langsung mendarat di halamannya tanpa login ulang.
 *
 * Dipakai pop-up sesudah Posting ke BO: nomor yang baru dikirim bisa diklik dan membuka
 * BO → Posting Invoice dari FO yang sudah tersaring ke nomor itu.
 */
final class TautanBo
{
    /** Batas tautan per pop-up — selebihnya disebut jumlahnya saja. */
    public const MAKS_TAUTAN = 6;

    /** URL FO yang membuka $pathBo di BO lewat SSO. */
    public static function url(string $pathBo): string
    {
        return url('/sso/to-bo').'?next='.urlencode($pathBo);
    }

    /** BO → Posting Invoice dari FO, tersaring ke $cari (No. Dokumen / No. Pengajuan). */
    public static function posting(string $cari): string
    {
        return self::url('/business-support/payable-posting?cari='.rawurlencode($cari));
    }

    /**
     * Tombol-tautan untuk Notification::actions() — satu per nomor, membuka tab baru.
     *
     * @param  array<int,string>  $nomor
     * @return array<int,Action>
     */
    public static function aksiPosting(array $nomor): array
    {
        $nomor = array_values(array_unique(array_filter(array_map('strval', $nomor))));

        return array_map(
            fn (string $no, int $i) => Action::make('bo_'.$i)->label($no.' ↗')->link()
                ->url(self::posting($no), shouldOpenInNewTab: true),
            array_slice($nomor, 0, self::MAKS_TAUTAN),
            array_keys(array_slice($nomor, 0, self::MAKS_TAUTAN)),
        );
    }

    /** Kalimat tambahan bila nomornya lebih banyak dari yang ditautkan. */
    public static function sisa(array $nomor): string
    {
        $n = count(array_unique(array_filter($nomor))) - self::MAKS_TAUTAN;

        return $n > 0 ? ' (+'.$n.' lainnya — cari di Posting Invoice BO)' : '';
    }

    /**
     * Path tujuan yang AMAN untuk diteruskan: hanya path relatif di dalam aplikasi itu sendiri
     * ("/..."). URL lengkap, "//host", backslash, atau baris baru ditolak — tanpa ini tautan
     * SSO bisa dipakai mengarahkan orang ke situs luar sesudah login (open redirect).
     */
    public static function tujuanAman(mixed $next): ?string
    {
        $n = is_string($next) ? trim($next) : '';

        if ($n === '' || ! str_starts_with($n, '/') || str_starts_with($n, '//')
            || str_contains($n, '\\') || preg_match('/[\x00-\x1F]/', $n)) {
            return null;
        }

        return mb_substr($n, 0, 500);
    }
}

<?php

namespace App\Filament\Concerns;

use App\Support\Akses;

/**
 * Sambungkan HALAMAN Filament (Pages) ke izin yang sudah terdaftar.
 *
 * Dipisah dari MematuhiIzin karena Resource dan Page memakai pintu yang berbeda:
 * Resource disaring lewat canViewAny(), sedangkan Page lewat canAccess(). Satu
 * trait untuk keduanya berarti method yang tidak dipakai ikut terbawa.
 *
 * canAccess() mengurus DUA hal sekaligus di Filament: item menunya disembunyikan
 * DAN rutenya ditolak. Jadi menyembunyikan menu di sini bukan sekadar kosmetik —
 * mengetik alamatnya langsung juga tertutup.
 *
 * Halaman yang belum dipetakan di config/akses.php tetap lolos (Akses::boleh
 * mengembalikan true untuk izin kosong) dan dilaporkan `akses:audit` sebagai
 * belum berpenjaga — supaya penambahan bertahap tidak mematikan menu yang
 * pemetaannya belum sempat ditinjau.
 */
trait MematuhiIzinHalaman
{
    public static function canAccess(): bool
    {
        return Akses::boleh(Akses::izinHalaman(static::class));
    }
}

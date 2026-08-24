<?php

namespace App\Filament\Concerns;

use App\Support\Akses;

/**
 * Sambungkan resource Filament ke izin yang sudah terdaftar.
 *
 * Dipasang lewat trait, bukan disunting satu per satu di 85 resource: pemetaannya
 * ada di config/akses.php sehingga bisa ditinjau dalam satu berkas, dan menambah
 * resource berikutnya tidak perlu menyentuh kode.
 *
 * Resource yang belum dipetakan tetap lolos — dilaporkan `akses:audit` sebagai
 * belum berpenjaga. Menutupnya lebih dulu berarti mematikan menu yang pemetaannya
 * belum sempat ditinjau.
 */
trait MematuhiIzin
{
    public static function canViewAny(): bool
    {
        return Akses::boleh(Akses::izinUntuk(static::class, 'viewAny'));
    }

    public static function canView(mixed $record): bool
    {
        return Akses::boleh(Akses::izinUntuk(static::class, 'view'));
    }

    public static function canCreate(): bool
    {
        return Akses::boleh(Akses::izinUntuk(static::class, 'create'));
    }

    public static function canEdit(mixed $record): bool
    {
        return Akses::boleh(Akses::izinUntuk(static::class, 'update'));
    }

    public static function canDelete(mixed $record): bool
    {
        return Akses::boleh(Akses::izinUntuk(static::class, 'delete'));
    }

    public static function canDeleteAny(): bool
    {
        return Akses::boleh(Akses::izinUntuk(static::class, 'delete'));
    }
}

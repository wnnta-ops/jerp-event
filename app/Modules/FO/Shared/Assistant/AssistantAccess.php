<?php

namespace App\Modules\FO\Shared\Assistant;

use App\Models\User;

/**
 * Gerbang akses READ-ONLY untuk Asisten AI.
 *
 * Prinsip: pengguna hanya boleh MEMBACA data yang menjadi haknya. Asisten tidak
 * pernah membuat/mengubah/menghapus/menyetujui apa pun — hanya izin baca (`.index`)
 * yang diperiksa, tak pernah `.store/.update/.destroy/.approve`.
 *
 * Aturan izin (selaras Gate::before SuperAdmin di ipro_bo):
 *   - peran super (super_admin / SuperAdmin) → lolos semua.
 *   - selain itu → harus punya permission baca yang diminta (spatie `can()`).
 * Bila izin belum di-assign ke peran, pengguna non-super memang belum dapat akses
 * (sesuai "hanya sesuai hak yang diberikan") sampai admin memberikannya.
 */
class AssistantAccess
{
    /** Peran dengan akses baca penuh. */
    private const SUPER_ROLES = ['super_admin', 'SuperAdmin'];

    /** Aksi baca pada konvensi permission FO: fo.<resource>.index */
    public static function readPermission(string $resourceSlug): string
    {
        return 'fo.'.$resourceSlug.'.index';
    }

    /** True bila $user boleh MEMBACA data dengan permission $permission. */
    public static function allows(?User $user, string $permission): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->hasAnyRole(self::SUPER_ROLES)) {
            return true;
        }

        return $user->can($permission);
    }
}

<?php

namespace App\Modules\Event\Services;

use App\Models\User;
use App\Modules\Event\Models\EtSetupDraft;

/**
 * Draft wizard "Buat event" milik akun yang sedang login — satu draft per akun,
 * tak terlihat akun lain. Isinya mentah (belum divalidasi); validasi tetap
 * terjadi saat event benar-benar dibuat.
 */
class EventDraftService
{
    /** @param array<string, mixed> $data */
    public function simpan(User $user, array $data): EtSetupDraft
    {
        return EtSetupDraft::query()->updateOrCreate(['user_id' => $user->id], ['data' => $data]);
    }

    public function ambil(User $user): ?EtSetupDraft
    {
        return EtSetupDraft::query()->where('user_id', $user->id)->first();
    }

    public function buang(User $user): void
    {
        EtSetupDraft::query()->where('user_id', $user->id)->delete();
    }
}

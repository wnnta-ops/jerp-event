<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Satu pintu untuk seluruh pemeriksaan izin.
 *
 * Dibuat terpusat supaya sakelar MODE LAPOR benar-benar berlaku di semua tempat.
 * Kalau tiap resource memanggil `$user->can()` sendiri-sendiri, menyalakan
 * penegakan berarti menyalakannya sekaligus di 85 tempat — tanpa cara aman untuk
 * melihat lebih dulu siapa yang akan terkunci.
 */
class Akses
{
    /**
     * Apakah pengguna boleh melakukan sesuatu?
     *
     * Dalam MODE LAPOR selalu mengembalikan true, tetapi penolakan yang SEHARUSNYA
     * terjadi dicatat ke log dengan kanal 'akses' — itulah bahan `akses:audit`.
     */
    public static function boleh(?string $izin, ?User $user = null): bool
    {
        $user ??= Auth::user();

        // Belum login bukan urusan lapisan ini — ditangani middleware auth.
        if (! $user instanceof User) {
            return false;
        }

        // Resource tanpa pemetaan izin dibiarkan lewat, dan dicatat supaya
        // ketahuan mana yang belum berpenjaga.
        if ($izin === null || $izin === '') {
            return true;
        }

        if (self::peranSuper($user)) {
            return true;
        }

        if ($user->can($izin)) {
            return true;
        }

        if (! self::tegakkan()) {
            Log::channel('single')->info('[akses] AKAN DITOLAK (mode lapor)', [
                'user_id' => $user->id,
                'email' => $user->email,
                'peran' => $user->getRoleNames()->implode(','),
                'izin' => $izin,
            ]);

            return true;
        }

        return false;
    }

    public static function tegakkan(): bool
    {
        return (bool) config('akses.tegakkan', false);
    }

    public static function peranSuper(User $user): bool
    {
        foreach ((array) config('akses.peran_super', []) as $peran) {
            if ($user->hasRole($peran)) {
                return true;
            }
        }

        return false;
    }

    /** Awalan izin untuk sebuah kelas resource Filament, atau null bila belum dipetakan. */
    public static function awalanResource(string $kelasResource): ?string
    {
        $pendek = class_basename($kelasResource);

        return config('akses.resource.'.$pendek);
    }

    /** Susun nama izin lengkap dari awalan + aksi Filament, mis. 'fo.nup' + 'viewAny' → 'fo.nup.index'. */
    public static function izinUntuk(string $kelasResource, string $aksi): ?string
    {
        $awalan = self::awalanResource($kelasResource);
        $akhiran = config('akses.aksi.'.$aksi);

        return ($awalan && $akhiran) ? $awalan.'.'.$akhiran : null;
    }
}

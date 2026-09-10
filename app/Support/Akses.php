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

    /**
     * Awalan izin untuk sebuah kelas resource Filament, atau null bila belum dipetakan.
     *
     * Nama kelas PENUH dicoba lebih dulu karena ada nama pendek yang dipakai dua
     * panel sekaligus (BankResource ada di Fo dan System). Tanpa itu keduanya
     * terpaksa berbagi satu izin, padahal isinya beda.
     */
    public static function awalanResource(string $kelasResource): ?string
    {
        return config('akses.resource.'.$kelasResource)
            ?? config('akses.resource.'.class_basename($kelasResource));
    }

    /** Susun nama izin lengkap dari awalan + aksi Filament, mis. 'fo.nup' + 'viewAny' → 'fo.nup.index'. */
    public static function izinUntuk(string $kelasResource, string $aksi): ?string
    {
        $awalan = self::awalanResource($kelasResource);
        $akhiran = config('akses.aksi.'.$aksi);

        return ($awalan && $akhiran) ? $awalan.'.'.$akhiran : null;
    }

    /**
     * Bolehkah pengguna melihat menu yang menuju rute Filament ini?
     *
     * Sidebar FO TIDAK dibangun otomatis oleh Filament — panelnya memakai
     * NavigationBuilder dengan NavigationItem yang dirakit dari URL. Akibatnya
     * canViewAny()/canAccess() milik resource & halaman tidak pernah ditanya
     * untuk menu, jadi grup tanpa satu izin pun tetap melihat seluruh menu FO
     * (rutenya sendiri tetap tertutup — yang bocor hanya daftar menunya).
     *
     * Di sini nama rute dipetakan balik ke kelas Filamentnya, lalu izinnya
     * ditanyakan ke kelas itu — sumber kebenarannya tetap satu.
     *
     * @param  string  $awalan  Awalan nama rute, mis. 'filament.fo.resources.sales.'
     */
    public static function bolehRute(string $awalan): bool
    {
        return static::$cacheRute[$awalan] ??= static::hitungBolehRute($awalan);
    }

    /** @var array<string,bool> */
    protected static array $cacheRute = [];

    private static function hitungBolehRute(string $awalan): bool
    {
        $kelas = static::kelasRute($awalan);

        // Rute tak dikenal dibiarkan tampil. Menyembunyikan yang tak bisa
        // dipetakan berarti menu hilang tanpa ada izin yang bisa diberikan
        // untuk memunculkannya kembali.
        if ($kelas === null) {
            return true;
        }

        // Halaman resource (ListX/EditX) → tanyakan resource-nya.
        if (method_exists($kelas, 'getResource')) {
            $resource = $kelas::getResource();

            return method_exists($resource, 'canViewAny') ? (bool) $resource::canViewAny() : true;
        }

        return method_exists($kelas, 'canAccess') ? (bool) $kelas::canAccess() : true;
    }

    /** Kelas Filament di balik sebuah awalan nama rute, atau null bila tak ketemu. */
    private static function kelasRute(string $awalan): ?string
    {
        $routes = app('router')->getRoutes();

        // Resource memakai akhiran 'index'; halaman namanya sudah utuh.
        foreach ([rtrim($awalan, '.').'.index', rtrim($awalan, '.')] as $nama) {
            $r = $routes->getByName($nama);
            if ($r && is_string($k = $r->getAction('controller'))) {
                return str_contains($k, '@') ? explode('@', $k)[0] : $k;
            }
        }

        return null;
    }

    /**
     * Izin untuk sebuah HALAMAN Filament, atau null bila belum dipetakan.
     *
     * Halaman tidak punya aksi CRUD seperti resource — membukanya cuma satu
     * tindakan — jadi yang dipakai selalu akhiran 'index'.
     */
    public static function izinHalaman(string $kelasHalaman): ?string
    {
        $awalan = config('akses.halaman.'.$kelasHalaman)
            ?? config('akses.halaman.'.class_basename($kelasHalaman));

        return $awalan ? $awalan.'.'.config('akses.aksi.viewAny', 'index') : null;
    }
}

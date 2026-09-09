<?php

namespace App\Console\Commands\Akses;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Buat izin yang dibutuhkan oleh pemetaan di config/akses.php, lalu pastikan
 * peran super admin memegang SELURUH izin.
 *
 * Sebelum ini ada 95 resource & halaman Filament tanpa izin sama sekali. Halaman
 * tanpa izin tidak bisa "diberikan" ke siapa pun — jadi menyalakan mode TEGAK
 * lebih dulu bukan menyembunyikannya, melainkan menghilangkannya permanen.
 * Perintah ini menutup celah itu.
 *
 * TIDAK menghapus apa pun dan TIDAK menyentuh peran selain super admin: izin
 * yang sudah dibagikan ke peran lain tetap utuh. Aman dijalankan berulang.
 */
class LengkapiIzinCommand extends Command
{
    protected $signature = 'akses:izin-lengkapi
        {--jalankan : Benar-benar menulis ke database. Tanpa ini hanya menampilkan rencana.}
        {--guard=web : Guard untuk izin yang dibuat.}';

    protected $description = 'Buat izin yang belum ada sesuai config/akses.php & beri super admin akses penuh';

    public function handle(): int
    {
        $guard = (string) $this->option('guard');
        $tulis = (bool) $this->option('jalankan');

        $perlu = $this->izinYangDibutuhkan();
        $ada = Permission::query()->where('guard_name', $guard)->pluck('name')->all();
        $kurang = array_values(array_diff($perlu, $ada));
        sort($kurang);

        $this->newLine();
        $this->line('  Dibutuhkan pemetaan : '.count($perlu));
        $this->line('  Sudah ada           : '.(count($perlu) - count($kurang)));
        $this->line('  Akan dibuat         : '.count($kurang));

        if ($kurang !== []) {
            $this->newLine();
            foreach (array_chunk($kurang, 4) as $baris) {
                $this->line('    '.implode('   ', array_map(fn ($x) => str_pad($x, 34), $baris)));
            }
        }

        if (! $tulis) {
            $this->newLine();
            $this->warn('  RENCANA saja — tidak ada yang ditulis. Tambahkan --jalankan untuk menerapkan.');

            return self::SUCCESS;
        }

        foreach ($kurang as $nama) {
            Permission::create(['name' => $nama, 'guard_name' => $guard]);
        }

        $this->newLine();
        $this->info('  '.count($kurang).' izin dibuat.');

        // Super admin WAJIB memegang semuanya. Tanpa ini, izin yang baru dibuat
        // tidak dimiliki siapa pun — termasuk yang seharusnya bisa membagikannya.
        foreach ((array) config('akses.peran_super', []) as $namaPeran) {
            $peran = Role::query()->where('name', $namaPeran)->where('guard_name', $guard)->first();
            if (! $peran) {
                $this->warn("  Peran super '{$namaPeran}' tidak ada — dilewati.");

                continue;
            }
            $peran->syncPermissions(Permission::query()->where('guard_name', $guard)->get());
            $this->info("  Peran '{$namaPeran}' kini memegang {$peran->permissions()->count()} izin.");
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->info('  Cache izin disegarkan.');

        return self::SUCCESS;
    }

    /**
     * Nama izin lengkap yang disiratkan config/akses.php.
     *
     * Resource dapat seluruh kosakata aksi (index/store/update/destroy/…),
     * halaman hanya 'index' karena membukanya cuma satu tindakan.
     *
     * @return list<string>
     */
    private function izinYangDibutuhkan(): array
    {
        $aksi = array_values(array_unique(array_values((array) config('akses.aksi', []))));
        $perlu = [];

        foreach ((array) config('akses.resource', []) as $awalan) {
            foreach ($aksi as $a) {
                $perlu[] = $awalan.'.'.$a;
            }
        }

        $indeks = config('akses.aksi.viewAny', 'index');
        foreach ((array) config('akses.halaman', []) as $awalan) {
            $perlu[] = $awalan.'.'.$indeks;
        }

        foreach ((array) config('akses.panel', []) as $izin) {
            $perlu[] = $izin;
        }

        foreach ((array) config('akses.modul', []) as $izin) {
            $perlu[] = $izin;
        }

        return array_values(array_unique($perlu));
    }
}

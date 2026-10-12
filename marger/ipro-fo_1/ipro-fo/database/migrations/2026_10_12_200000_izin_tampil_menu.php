<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Izin TAMPIL per butir menu — satu sakelar sidebar untuk tiap baris di layar Grup Akses BO.
 *
 * ── PERSOALANNYA ──
 *
 * Satu izin aksi kerap membuka BEBERAPA menu sekaligus: `ap.invoice.view` menerbitkan Invoice
 * Hutang, Laporan Invoice Hutang, dan Registrasi Invoice Hutang; `gl.journal.view` menerbitkan
 * enam menu. Akibatnya layar Grup Akses BO tidak pernah bisa dibuat secermin sidebar yang
 * dilihat pengguna — admin IT mencentang satu baris, lalu tiga menu muncul di tempat lain.
 *
 * Izin `menu.*` memisahkan dua pertanyaan yang selama ini tercampur:
 *
 *     izin aksi    boleh tidaknya layarnya dibuka   (dijaga juga di controller)
 *     izin tampil  kelihatan tidaknya butir sidebar (dibaca Sidebar.vue)
 *
 * Keduanya harus terpenuhi untuk sebuah butir muncul — DAN, bukan ATAU.
 *
 * ── KENAPA DIWARISKAN, BUKAN DIBIARKAN KOSONG ──
 *
 * Izin baru lahir tanpa pemegang. Kalau sidebar mulai menuntutnya sementara belum ada yang
 * memilikinya, SELURUH sidebar semua orang kosong begitu deploy selesai — termasuk Finance
 * yang sedang mengerjakan pembayaran. Maka tiap izin tampil diberikan kepada setiap peran dan
 * pengguna yang HARI INI memegang izin aksi butir itu. Hari pertama setelah migrasi, sidebar
 * setiap orang persis sama seperti sebelumnya; yang bertambah hanya kemampuan admin IT untuk
 * mematikan butir satu per satu sesudahnya.
 *
 * Katalognya di config/menu_permissions.php, diturunkan dari navGroups.ts — sumber yang sama
 * dengan sidebar. Butir yang tidak tercantum di sana akan selalu tampil, jadi menu baru yang
 * lupa didaftarkan tidak pernah hilang diam-diam.
 */
return new class extends Migration
{
    public function up(): void
    {
        $katalog = self::katalog();

        if ($katalog === []) {
            // Lebih baik gagal keras daripada tercatat "Ran" tanpa membuat satu izin pun:
            // sidebar menuntut izin tampil, jadi semua menu hilang untuk selain SuperAdmin.
            throw new RuntimeException('Katalog config/menu_permissions.php kosong atau tak terbaca.');
        }

        foreach ($katalog as $nama => [$izinAksi, $jalur]) {
            $id = DB::table('permissions')->where('name', $nama)->where('guard_name', 'web')->value('id');

            if ($id === null) {
                $id = DB::table('permissions')->insertGetId([
                    'name' => $nama,
                    'guard_name' => 'web',
                    'module' => 'menu',
                    'label' => 'Tampil: '.$jalur,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Pola 'x.*' (SIP, menu MUK) dibaca sidebar sebagai "izin apa pun berawalan x." —
            // lihat cocokIzin di useIzin.ts. Pewarisannya harus sama persis; mencarinya sebagai
            // nama izin harfiah tidak menemukan apa-apa, dan menunya hilang untuk semua orang.
            $kueri = DB::table('permissions')->where('guard_name', 'web');
            $idAksi = str_ends_with($izinAksi, '.*')
                ? $kueri->where('name', 'like', substr($izinAksi, 0, -1).'%')->pluck('id')
                : $kueri->where('name', $izinAksi)->pluck('id');

            if ($idAksi->isEmpty()) {
                continue;   // izin aksinya belum ada di pemasangan ini — tidak ada yang bisa diwarisi
            }

            // insertOrIgnore: satu peran bisa memegang izin aksi yang sama untuk beberapa butir
            // menu, dan tanpa ini baris keduanya melanggar primary key.
            foreach (DB::table('role_has_permissions')->whereIn('permission_id', $idAksi)->distinct()->pluck('role_id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $id,
                    'role_id' => $roleId,
                ]);
            }

            // Izin yang diberikan LANGSUNG ke orang, di luar perannya — ikut diwariskan, kalau
            // tidak orang yang aksesnya memang disetel khusus justru kehilangan menunya.
            foreach (DB::table('model_has_permissions')->whereIn('permission_id', $idAksi)->get() as $baris) {
                $data = (array) $baris;
                $data['permission_id'] = $id;
                DB::table('model_has_permissions')->insertOrIgnore($data);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $nama = array_keys(self::katalog());

        if ($nama === []) {
            return;
        }

        $ids = DB::table('permissions')->whereIn('name', $nama)->pluck('id');

        if ($ids->isNotEmpty()) {
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Dibaca LANGSUNG dari berkasnya, bukan lewat config().
     *
     * Kode BO di-bind-mount, jadi bootstrap/cache/config.php dari deploy sebelumnya masih ada
     * saat up-bo.sh menjalankan migrate — config:cache baru dijalankan sesudahnya. Lewat
     * config(), katalog yang baru ditarik git tidak terbaca dan hasilnya array kosong.
     *
     * @return array<string,array{0:string,1:string}>
     */
    private static function katalog(): array
    {
        return require base_path('config/menu_permissions.php');
    }
};

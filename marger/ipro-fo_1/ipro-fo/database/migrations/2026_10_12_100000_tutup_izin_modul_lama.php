<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Bersih-bersih katalog izin BO: cabut izin modul yang sudah pindah ke FO, dan lepaskan
 * COGS Baru dari izin milik COGS Lama sebelum yang lama ikut dicabut.
 *
 * ── 1. IZIN MODUL YANG SUDAH PINDAH KE FO ──
 *
 * Sales, Finance, Collection, Reporting & Approval dikerjakan sepenuhnya di FO. Rutenya di
 * BO sudah dimatikan config/modul_pindah_fo.php (bawaannya false, jadi tidak pernah
 * terdaftar), dan PermissionSeeder sudah lama berhenti membuatnya. Yang tertinggal cuma
 * baris izinnya di tabel — dibuat sebelum sakelar itu ada.
 *
 * Diperiksa di server lebih dulu: NOL peran memegangnya. Jadi mencabutnya tidak mencabut
 * akses siapa pun; ia hanya berhenti memenuhi layar Grup Akses BO dengan 26 baris yang
 * tidak menuju ke mana-mana — dan baris yang tidak menuju ke mana-mana pada layar hak
 * akses bukan sekadar berantakan: orang mencentanginya, mengira sudah memberi akses.
 *
 * `master.*` TIDAK ikut: dipegang 6 peran dan dipakai modul KPR yang aktif.
 * `businesssupport.*` TIDAK ikut: itu izin Back Office sendiri.
 *
 * ── 2. COGS BARU DILEPAS DARI IZIN COGS LAMA ──
 *
 * Seluruh layar COGS Baru digerbangi `cogs.budget.view` — izin milik COGS Lama (menu
 * Setup → Budget). Selama masih begitu, mencabut izin COGS Lama berarti mematikan COGS
 * Baru yang justru sedang dipakai (1 periode, 222 unit saat migrasi ini ditulis).
 *
 * Maka urutannya: izin `cogs2.*` dibuat, SIAPA PUN yang hari ini bisa membuka/mengubah
 * COGS Baru diberi izin barunya persis sesuai akses lamanya, baru `cogs.*` dicabut. Tidak
 * ada satu orang pun yang kehilangan atau mendapat akses karena migrasi ini.
 *
 * Datanya tidak disentuh. Tabel `bs_cogs_*` beserta 71 baris HPP RealCost-nya tetap utuh —
 * semuanya berstatus `generated` dengan nol jurnal, jadi buku besar memang tidak pernah
 * tersentuh, tetapi menghapus data bukan urusan migrasi izin.
 */
return new class extends Migration
{
    /** Izin baru milik COGS Baru. */
    private const COGS2 = [
        'cogs2.view' => 'Lihat',
        'cogs2.manage' => 'Kelola',
    ];

    /**
     * Izin lama => izin cogs2 penggantinya. Siapa pun yang memegang yang kiri
     * mendapat yang kanan, supaya aksesnya hari ini dan besok sama persis.
     */
    private const WARIS = [
        'cogs.budget.view' => 'cogs2.view',
        'businesssupport.setup.manage' => 'cogs2.manage',
        'cogs.component.manage' => 'cogs2.manage',
    ];

    /** Awalan izin yang dicabut. Tidak termasuk 'master.' dan 'businesssupport.'. */
    private const DICABUT = ['sales.', 'finance.', 'collection.', 'reporting.', 'approval.', 'cogs.'];

    public function up(): void
    {
        foreach (self::COGS2 as $nama => $label) {
            if (DB::table('permissions')->where('name', $nama)->where('guard_name', 'web')->exists()) {
                continue;
            }

            DB::table('permissions')->insert([
                'name' => $nama,
                'guard_name' => 'web',
                'module' => 'cogs2',
                'label' => $label,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (self::WARIS as $lama => $baru) {
            $idLama = DB::table('permissions')->where('name', $lama)->where('guard_name', 'web')->value('id');
            $idBaru = DB::table('permissions')->where('name', $baru)->where('guard_name', 'web')->value('id');

            if ($idLama === null || $idBaru === null) {
                continue;
            }

            // Peran. insertOrIgnore, bukan insert: satu peran bisa mewarisi dari dua
            // izin lama sekaligus (punya businesssupport.setup.manage DAN
            // cogs.component.manage), dan tanpa ini baris keduanya melanggar primary key.
            foreach (DB::table('role_has_permissions')->where('permission_id', $idLama)->pluck('role_id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $idBaru,
                    'role_id' => $roleId,
                ]);
            }

            // Izin yang diberikan LANGSUNG ke orang, di luar perannya — ikut diwariskan,
            // kalau tidak orang yang aksesnya memang disetel khusus justru kehilangannya.
            foreach (DB::table('model_has_permissions')->where('permission_id', $idLama)->get() as $baris) {
                $data = (array) $baris;
                $data['permission_id'] = $idBaru;
                DB::table('model_has_permissions')->insertOrIgnore($data);
            }
        }

        foreach (self::DICABUT as $awalan) {
            $ids = DB::table('permissions')->where('name', 'like', $awalan.'%')->pluck('id');

            if ($ids->isEmpty()) {
                continue;
            }

            // Pemberian izinnya dilepas lebih dulu. Tidak semua pemasangan memberi
            // ON DELETE CASCADE pada tabel pivot Spatie, dan baris pivot yatim membuat
            // layar Grup Akses memuat izin yang sudah tidak ada.
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Izin yang dicabut TIDAK dikembalikan.
     *
     * Mengembalikannya berarti menerbitkan ulang 26+ baris izin yang menuju rute yang
     * memang sudah mati — tanpa satu pun pemberiannya, sebab yang memegangnya nol. Yang
     * dibalik hanya pelepasan COGS Baru, supaya rollback tidak meninggalkan modul yang
     * sedang dipakai tanpa gerbang sama sekali.
     */
    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::COGS2))->pluck('id');

        if ($ids->isNotEmpty()) {
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

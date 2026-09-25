<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Membuang sisa "Setup Batal Angsuran" — fitur itu dihapus karena tidak diperlukan.
 *
 * Kode layarnya sudah lenyap dari repo, tapi tiga jejaknya terlanjur ada di database
 * server dan tidak akan hilang sendiri:
 *
 *   1. kolom fo_tahap.persen_batal_admin
 *   2. baris setelan collection.batal_admin_persen
 *   3. izin fo.setup_batal_angsuran.index
 *
 * Izinnya wajib ikut dibuang. Bila ditinggal, ia tetap muncul di layar Peran sebagai
 * kotak centang yang bisa diberikan kepada siapa pun padahal tidak membuka apa-apa.
 *
 * Semua langkah berpenjaga "bila ada", sehingga database yang dibangun dari nol — yang
 * memang tidak pernah punya ketiganya, karena migrasi pembuatnya ikut dihapus — melewati
 * migrasi ini tanpa galat.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('fo_tahap', 'persen_batal_admin')) {
            Schema::table('fo_tahap', function (Blueprint $t) {
                $t->dropColumn('persen_batal_admin');
            });
        }

        DB::table('settings')
            ->where('group', 'collection')
            ->where('name', 'batal_admin_persen')
            ->delete();

        // Penghapusan izin ikut membersihkan role_has_permissions & model_has_permissions
        // lewat foreign key on delete cascade bawaan spatie/laravel-permission.
        DB::table('permissions')->where('name', 'fo.setup_batal_angsuran.index')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Sengaja tidak melakukan apa pun. Membalik migrasi ini berarti menghidupkan lagi
        // kolom, setelan, dan izin milik fitur yang kodenya sudah tidak ada — hasilnya
        // hanya sampah yang tak tersentuh apa pun.
    }
};

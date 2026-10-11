<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda: Grup Akses BO pengguna ini DIATUR MANUAL dari panel System.
 *
 * ── PERSOALAN YANG DIPECAHKAN ──
 *
 * Grup Akses BO punya DUA pemilik yang sama-sama merasa berwenang:
 *
 *   1. Panel System (UserResource) — admin IT memilihnya langsung.
 *   2. IproMasterSync::syncBoRolesFromFo() — menyelaraskannya mengikuti peran FO
 *      lewat config ipro.role_map, dan ia otoritatif atas SETIAP nama peran yang
 *      muncul di peta itu.
 *
 * Akibatnya peran yang dipasang admin lenyap beberapa detik kemudian, tanpa galat:
 * panel menulis [Viewer, Admin], job sinkron berjalan, "Admin" terhitung dikelola peta
 * lalu dibuang. Penyimpanan berhasil — dan hasilnya tetap hilang saat halaman dimuat ulang.
 *
 * ── CARA KERJANYA SEKARANG ──
 *
 * Begitu admin menyimpan Grup Akses BO dari panel, kolom ini menjadi true dan sinkron
 * BERHENTI menyentuh grup BO pengguna tersebut. Peta peran tetap bekerja untuk pengguna
 * BARU — itulah gunanya: mengisi nilai awal, bukan memaksakan selamanya.
 *
 * Konsekuensi yang disengaja: setelah diatur manual, mengubah peran FO orang itu TIDAK
 * lagi otomatis mengubah grup BO-nya. Memang begitu artinya "diatur manual"; panel
 * menyediakan sakelar untuk mengembalikannya ke otomatis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('bo_roles_manual')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('bo_roles_manual');
        });
    }
};

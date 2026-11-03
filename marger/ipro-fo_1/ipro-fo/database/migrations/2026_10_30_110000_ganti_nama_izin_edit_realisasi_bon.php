<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * cashbook.advance.realize.edit → cashbook.advance.realize-edit.
 *
 * Matriks Grup Akses memecah nama izin per titik: empat segmen terbaca sebagai kelompok
 * "advance.realize" beraksi "edit", sehingga centangnya tidak muncul di baris Bon Sementara.
 * Diganti nama (id tetap) — role yang sudah memegangnya tidak ikut hilang.
 */
return new class extends Migration
{
    private const LAMA = 'cashbook.advance.realize.edit';

    private const BARU = 'cashbook.advance.realize-edit';

    public function up(): void
    {
        $this->ganti(self::LAMA, self::BARU);
    }

    public function down(): void
    {
        $this->ganti(self::BARU, self::LAMA);
    }

    private function ganti(string $dari, string $ke): void
    {
        if (! DB::table('permissions')->where('name', $ke)->where('guard_name', 'web')->exists()) {
            DB::table('permissions')->where('name', $dari)->where('guard_name', 'web')
                ->update(['name' => $ke, 'updated_at' => now()]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

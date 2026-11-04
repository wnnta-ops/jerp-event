<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Batas invoice menyusul Memo Urgent yang sudah dibayar: 14 → 7 hari. Satu batas untuk pop-up
 * pengingat saat login (hitung mundur) dan blokir pengajuan memo baru. Nilai yang sudah diubah
 * admin (bukan 14) tidak disentuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('parameters')->where('group', 'memo')->where('key', 'batas_hari_invoice')->where('value', '14')
            ->update(['value' => '7', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('parameters')->where('group', 'memo')->where('key', 'batas_hari_invoice')->where('value', '7')
            ->update(['value' => '14', 'updated_at' => now()]);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Format No NUP dipersingkat menjadi `NUP-{seq:5}` (tanpa tanggal). Reset
 * baris numerator existing supaya nomor selanjutnya pakai format baru.
 * Aman dijalankan ulang — hanya update baris dengan kode 'NUP'.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('numerators')
            ->where('code', 'NUP')
            ->update([
                'format' => 'NUP-{seq:5}',
                'period' => null,
                'last_seq' => 0,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('numerators')
            ->where('code', 'NUP')
            ->update([
                'format' => 'NUP-{YYYY}{MM}{DD}-{seq:4}',
                'period' => date('Y'),
                'updated_at' => now(),
            ]);
    }
};

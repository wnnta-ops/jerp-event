<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Arahkan Bank Mandiri → Format SI Mandiri, dan Bank BNI → Format SI BNI.
 *
 * Sebelum ada kedua format bawaan ini, keduanya sempat memakai 'bri' sebagai penampung
 * sementara — surat yang tercetak jadi berformat BRI. Migrasi ini membetulkannya.
 *
 * Hanya menyentuh bank yang si_format-nya kosong atau masih memakai penampung sementara itu:
 * bila operator sudah sengaja memilih format lain, pilihannya TIDAK ditimpa.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'mandiri' => ['mandiri'],
            'bni'     => ['bni', 'negara indonesia'],
        ] as $format => $katas) {
            DB::table('bank_partners')
                ->where(function ($q) use ($katas) {
                    foreach ($katas as $k) {
                        $q->orWhere('name', 'like', '%'.$k.'%');
                    }
                })
                ->where(fn ($q) => $q->whereNull('si_format')->orWhere('si_format', '')->orWhere('si_format', 'bri'))
                ->update(['si_format' => $format]);
        }
    }

    public function down(): void
    {
        // Kembalikan ke penampung lama supaya turun-naik migrasi tidak meninggalkan
        // format yang tidak dikenal versi sebelumnya.
        DB::table('bank_partners')->whereIn('si_format', ['mandiri', 'bni'])->update(['si_format' => 'bri']);
    }
};

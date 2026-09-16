<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengaturan Kalkulator KPR — satu baris per tenant, isinya satu dokumen JSON.
 *
 * KENAPA SATU KOLOM JSON, BUKAN TABEL TERNORMALISASI (bank/skema/tier/promo):
 * seluruh DATA-nya hanya 12 KB — yang membuat config aslinya 2,5 MB adalah gambar base64,
 * dan gambar itu dipisah jadi berkas tersendiri di public/img/kpr-calculator. Bentuk
 * dokumennya juga persis bentuk yang dibaca mesin hitung dan panel adminnya; memecahnya
 * jadi empat tabel berarti merakit ulang bentuk itu di dua tempat dan membuka peluang
 * keduanya berbeda tanpa satu pun galat.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kpr_calculator_settings')) {
            Schema::create('kpr_calculator_settings', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained();
                $t->longText('config');           // dokumen KPR_CONFIG (bank, skema, promo, dll)
                $t->timestamps();
                $t->unique('tenant_id');          // satu pengaturan per tenant
            });
        }

        // Isi awal = konfigurasi aplikasi Kalkulator KPR Java Residence yang sedang berjalan,
        // dengan gambar sudah dipindah ke berkas (kolom gambarnya berisi path, bukan base64).
        $berkas = database_path('seeders/data/kpr-calculator-default.json');
        if (! is_file($berkas)) {
            return;
        }
        $bawaan = json_decode((string) file_get_contents($berkas), true);
        if (! is_array($bawaan)) {
            return;
        }

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $sudahAda = DB::table('kpr_calculator_settings')->where('tenant_id', $tenantId)->exists();
            if (! $sudahAda) {
                DB::table('kpr_calculator_settings')->insert([
                    'tenant_id' => $tenantId,
                    'config'    => json_encode($bawaan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kpr_calculator_settings');
    }
};

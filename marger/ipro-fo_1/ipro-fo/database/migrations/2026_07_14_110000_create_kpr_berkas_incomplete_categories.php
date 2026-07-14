<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Master "Kategori Berkas Tidak Lengkap" (KPR → Set Up). Menjadi sumber dropdown catatan
 * pada berkas berstatus "Tidak Lengkap" di tahap Collect Berkas (Inisiasi KPR).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kpr_berkas_incomplete_categories')) {
            return;
        }

        Schema::create('kpr_berkas_incomplete_categories', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('name');
            $t->unsignedInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });

        // Seed alasan default per tenant yang sudah ada.
        $defaults = ['Belum diserahkan customer', 'Dokumen kadaluarsa', 'Data tidak sesuai / tidak jelas', 'Menunggu dari pihak ketiga', 'Ditolak / tidak bersedia memberikan'];
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $sort = 1;
            foreach ($defaults as $name) {
                DB::table('kpr_berkas_incomplete_categories')->insert([
                    'tenant_id' => $tenantId, 'name' => $name, 'sort_order' => $sort++, 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kpr_berkas_incomplete_categories');
    }
};

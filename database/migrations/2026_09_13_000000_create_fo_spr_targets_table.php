<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Target SPR per bulan, satu baris per SKENARIO per tahun.
        //
        // Skenarionya sengaja teks bebas, bukan enum atau tiga kolom tetap: nama yang dipakai
        // di lapangan adalah label revisi yang lahir sesuai kejadian ("Target Adjust 1 (4&5 Feb
        // 26)", "Adjust 3 (Evaluasi Mei 26)"). Mematoknya berarti revisi berikutnya menuntut
        // perubahan kode & migrasi, padahal yang berubah hanya nama di berkas Excel.
        Schema::create('fo_spr_targets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedSmallInteger('tahun');
            $table->string('skenario', 120);
            // Urutan tampil di legenda grafik. Diisi dari urutan baris berkas impor supaya
            // "Target (WPnB)" tetap tampil sebelum "Adjust 1" walau namanya tidak urut abjad.
            $table->unsignedSmallInteger('urutan')->default(0);
            for ($m = 1; $m <= 12; $m++) {
                $table->integer('m'.$m)->default(0);
            }
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            // Wajib: TenantAwareModel memakai SoftDeletes, jadi SETIAP query modelnya
            // menyaring deleted_at. Tanpa kolom ini, model ini melempar "Unknown column"
            // pada pemakaian pertama — bukan diam-diam salah, tapi langsung mati.
            $table->softDeletes();

            // Satu skenario hanya boleh punya satu baris per tahun per tenant: impor ulang
            // MEMPERBARUI baris yang sama, bukan menumpuk skenario kembar yang lalu tampil
            // sebagai dua garis identik di grafik.
            $table->unique(['tenant_id', 'tahun', 'skenario'], 'fo_spr_target_unik');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_spr_targets');
    }
};

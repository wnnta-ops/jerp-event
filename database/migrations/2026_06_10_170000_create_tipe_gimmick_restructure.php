<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Setup Gimmick 2 level:
 *  - fo_tipe_gimmick : "Tipe Gimmick" (paket per project/cluster/waktu, mis.
 *    CLUSTER 3.3, REGULER, PRIORITAS). Bisa ditambah & diganti.
 *  - fo_gimmicks     : "Pendaftaran Gimmick" (item turunan tipe: Voucher 20jt,
 *    FREE BPHTB, dst). Ganti kolom enum `jenis` → tipe_gimmick_id + tambah
 *    satuan/jumlah/syarat persen pelunasan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_tipe_gimmick', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->string('kode', 32);
            $table->string('nama');
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'kode']);
        });

        Schema::table('fo_gimmicks', function (Blueprint $table) {
            $table->foreignId('tipe_gimmick_id')->nullable()->after('project_id')
                ->constrained('fo_tipe_gimmick')->nullOnDelete();
            $table->string('satuan', 32)->nullable()->after('name');     // UNIT, SET, VOUCHER
            $table->integer('jumlah')->default(1)->after('satuan');       // Jumlah Barang
            $table->decimal('syarat_persen_pelunasan', 8, 4)->default(0)->after('nilai');
            $table->dropColumn('jenis');
        });
    }

    public function down(): void
    {
        Schema::table('fo_gimmicks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tipe_gimmick_id');
            $table->dropColumn(['satuan', 'jumlah', 'syarat_persen_pelunasan']);
            $table->string('jenis')->nullable();
        });
        Schema::dropIfExists('fo_tipe_gimmick');
    }
};

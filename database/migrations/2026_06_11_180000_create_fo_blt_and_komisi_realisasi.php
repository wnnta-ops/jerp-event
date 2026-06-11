<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tabel BLT (hasil generate per level) + kolom realisasi (PPn/rekening) di komisi & blt. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_blt', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->string('no_blt')->nullable();
            $table->date('tgl_blt')->nullable();
            $table->foreignId('kontrak_id')->nullable()->constrained('fo_kontrak')->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('fo_agents')->nullOnDelete();
            $table->foreignId('sales_level_id')->nullable()->constrained('fo_sales_levels')->nullOnDelete();
            $table->string('level_type')->default('SALES');   // SALES / KOORDINATOR / KANTOR
            $table->string('nama_user')->nullable();
            $table->decimal('nilai_blt', 19, 2)->default(0);
            $table->string('pph_jenis')->nullable();          // PPh21 / PPh23
            $table->decimal('pph_persen', 8, 4)->default(0);
            $table->decimal('pph_amount', 19, 2)->default(0);
            $table->decimal('ppn_amount', 19, 2)->default(0);
            $table->decimal('nett', 19, 2)->default(0);
            $table->string('status')->default('DRAFT');       // DRAFT/APPROVED/PAID/BATAL
            $table->date('tgl_bayar')->nullable();
            $table->string('rek_bank')->nullable();
            $table->string('rek_number')->nullable();
            $table->string('rek_holder')->nullable();
            $table->text('keterangan')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'no_blt']);
            $table->index(['tenant_id', 'status']);
        });

        // Realisasi komisi: PPn + rekening (PPh sudah ada di fo_komisi).
        Schema::table('fo_komisi', function (Blueprint $table) {
            $table->decimal('ppn_amount', 19, 2)->default(0)->after('pph_amount');
            $table->string('rek_bank')->nullable()->after('tgl_bayar');
            $table->string('rek_number')->nullable()->after('rek_bank');
            $table->string('rek_holder')->nullable()->after('rek_number');
        });
    }

    public function down(): void
    {
        Schema::table('fo_komisi', function (Blueprint $table) {
            $table->dropColumn(['ppn_amount', 'rek_bank', 'rek_number', 'rek_holder']);
        });
        Schema::dropIfExists('fo_blt');
    }
};

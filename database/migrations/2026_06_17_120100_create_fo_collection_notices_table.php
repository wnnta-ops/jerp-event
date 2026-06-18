<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log surat penagihan (reminder pra-jatuh-tempo & Surat Peringatan I/II/III) yang
 * dikirim per tagihan kontrak. Dipakai untuk: menentukan level SP berjalan,
 * mencegah kirim ganda dalam satu siklus, dan riwayat/cetak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_collection_notices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('kontrak_id')->constrained('fo_kontrak')->cascadeOnDelete();
            $table->foreignId('kontrak_tagihan_id')->nullable()->constrained('fo_kontrak_tagihan')->nullOnDelete();
            $table->string('no_surat')->nullable();
            $table->string('jenis', 16);                 // REMINDER | SP1 | SP2 | SP3
            $table->string('channel', 16);               // EMAIL | WA
            $table->string('status', 16)->default('TERKIRIM'); // TERKIRIM | GAGAL
            $table->string('penerima')->nullable();      // email / no WA tujuan
            $table->string('subjek')->nullable();
            $table->text('isi')->nullable();
            $table->unsignedInteger('hari_telat')->default(0);
            $table->timestamp('dikirim_pada')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['kontrak_id', 'jenis']);
            $table->index(['kontrak_tagihan_id', 'jenis']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_collection_notices');
    }
};

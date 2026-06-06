<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_nup_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('nup_event_id')->constrained('fo_nup_events')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('no_nup', 32);
            $table->unsignedInteger('urutan');
            $table->foreignId('customer_id')->nullable()->constrained('fo_customers')->cascadeOnUpdate()->nullOnDelete();
            $table->string('nama_pendaftar');
            $table->string('nik', 32)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->dateTime('tgl_daftar');
            $table->dateTime('tgl_check_in')->nullable();
            $table->dateTime('tgl_payment')->nullable();
            $table->decimal('booking_fee_paid', 18, 2)->default(0);
            $table->foreignId('tts_id')->nullable()->constrained('fo_tts')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('fo_units')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('reservasi_id')->nullable()->constrained('fo_reservasi')->cascadeOnUpdate()->nullOnDelete();
            $table->enum('status', ['DAFTAR', 'CHECK_IN', 'BAYAR', 'PILIH_UNIT', 'BATAL', 'TIDAK_HADIR'])->default('DAFTAR');
            $table->string('channel', 32)->default('PUBLIC');   // PUBLIC, ADMIN, AGENT
            $table->string('recaptcha_score', 8)->nullable();
            $table->text('keterangan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'no_nup']);
            $table->unique(['nup_event_id', 'urutan']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'nup_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_nup_registrations');
    }
};

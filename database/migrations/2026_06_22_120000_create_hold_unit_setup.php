<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Setup Hold Unit: periode hold (rentang tanggal, maks 2 hari; >2 hari via Approval),
 * field hold di unit, dan jenis Approval HOLD. Sales bisa hold unit sementara saat
 * weekend/libur finance; auto-release bila tak ada transfer cocok dalam masa hold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_hold_periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->date('tgl_mulai');
            $table->date('tgl_selesai');
            $table->unsignedSmallInteger('max_hari')->default(2);
            $table->string('keterangan')->nullable();
            // ACTIVE (≤2 hari, langsung aktif) / PENDING (>2 hari, tunggu approval) / REJECTED / SELESAI
            $table->string('status', 16)->default('ACTIVE');
            $table->unsignedBigInteger('approval_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::table('fo_units', function (Blueprint $table) {
            $table->dateTime('hold_until')->nullable()->after('status');
            $table->unsignedBigInteger('hold_registration_id')->nullable()->after('hold_until');
            $table->unsignedBigInteger('hold_by')->nullable()->after('hold_registration_id');
        });

        DB::statement("ALTER TABLE fo_approvals MODIFY jenis ENUM('DISKON','DISKON_SPRS','BATAL_KONTRAK','ADJ_HARGA','RESCHEDULE','GANTI_NAMA','GANTI_UNIT','REFUND','BLT','KOMISI','HOLD','LAIN') NOT NULL");
    }

    public function down(): void
    {
        Schema::table('fo_units', function (Blueprint $table) {
            $table->dropColumn(['hold_until', 'hold_registration_id', 'hold_by']);
        });
        Schema::dropIfExists('fo_hold_periods');
        DB::statement("ALTER TABLE fo_approvals MODIFY jenis ENUM('DISKON','DISKON_SPRS','BATAL_KONTRAK','ADJ_HARGA','RESCHEDULE','GANTI_NAMA','GANTI_UNIT','REFUND','BLT','KOMISI','LAIN') NOT NULL");
    }
};

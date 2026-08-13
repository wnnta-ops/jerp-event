<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preferensi Unit kini SATU pilihan dari master fo_preferensi_unit.
        // Kolom lama pilihan_budget (json multi-pilih) DIBIARKAN apa adanya —
        // data pendaftaran lama tidak diubah surut; hanya pendaftaran baru yang
        // mengisi kolom di bawah ini. preferensi_unit_nama = snapshot nama.
        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            $table->foreignId('preferensi_unit_id')->nullable()->after('pilihan_budget')
                ->constrained('fo_preferensi_unit')->cascadeOnUpdate()->nullOnDelete();
            $table->string('preferensi_unit_nama', 100)->nullable()->after('preferensi_unit_id');
        });
    }

    public function down(): void
    {
        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            $table->dropColumn('preferensi_unit_nama');
            $table->dropConstrainedForeignId('preferensi_unit_id');
        });
    }
};

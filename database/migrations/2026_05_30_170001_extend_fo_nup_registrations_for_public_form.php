<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            // Project preferensi (PRD §3.1.1)
            $table->foreignId('project_pref_id')->nullable()->after('nup_event_id')
                ->constrained('projects')->cascadeOnUpdate()->nullOnDelete();

            // Referensi sales (PRD §3.1.1)
            $table->string('ref_sales_name')->nullable()->after('channel');
            $table->string('ref_sales_phone', 32)->nullable()->after('ref_sales_name');
            $table->string('tipe_nup', 32)->nullable()->after('ref_sales_phone');
            $table->string('cara_bayar', 32)->nullable()->after('tipe_nup');

            // Preferensi unit (tidak mengikat)
            $table->json('preferensi_unit')->nullable()->after('cara_bayar');

            // Pernyataan keabsahan data (BR-NUP-02)
            $table->boolean('pernyataan_setuju')->default(false)->after('preferensi_unit');

            // IP & user-agent untuk audit trail publik
            $table->string('ip_address', 64)->nullable()->after('pernyataan_setuju');
            $table->string('user_agent', 512)->nullable()->after('ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            $table->dropForeign(['project_pref_id']);
            $table->dropColumn([
                'project_pref_id', 'ref_sales_name', 'ref_sales_phone',
                'tipe_nup', 'cara_bayar', 'preferensi_unit',
                'pernyataan_setuju', 'ip_address', 'user_agent',
            ]);
        });
    }
};

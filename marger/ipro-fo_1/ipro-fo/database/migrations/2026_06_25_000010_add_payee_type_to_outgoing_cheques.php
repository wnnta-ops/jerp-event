<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tujuan BG/Cek: 'supplier' (atas pelunasan hutang — penerima Supplier/Vendor) atau
 * 'pihak_ketiga' (tidak berhubungan hutang — penerima pihak ketiga, bebas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_outgoing_cheques', function (Blueprint $table) {
            if (! Schema::hasColumn('bs_outgoing_cheques', 'payee_type')) {
                $table->string('payee_type', 20)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('bs_outgoing_cheques', function (Blueprint $table) {
            if (Schema::hasColumn('bs_outgoing_cheques', 'payee_type')) {
                $table->dropColumn('payee_type');
            }
        });
    }
};

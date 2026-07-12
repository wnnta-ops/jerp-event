<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            // Nama skema bayar ASLI dari FO (fo_kontrak.skema.name — mis. "KPR FREE DP", "CASH 3X"),
            // untuk ditampilkan pada kolom Cara Bayar (payment_scheme_id BO hanya menyimpan TIPE generik).
            $table->string('scheme_name', 100)->nullable()->after('payment_scheme_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('scheme_name');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_customers', function (Blueprint $table) {
            $table->string('npwp_pasangan_file_path')->nullable()->after('ktp_pasangan_file_path');
        });
    }

    public function down(): void
    {
        Schema::table('fo_customers', function (Blueprint $table) {
            $table->dropColumn('npwp_pasangan_file_path');
        });
    }
};

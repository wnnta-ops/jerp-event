<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Pembagian Biaya per Cash Flow" pada Rekening Ledger tidak lagi meminta cluster —
 * pembagian ditentukan oleh KATEGORI CASH FLOW saja. Karena itu cluster_id dibuat
 * boleh kosong; pembagian lama yang sudah punya cluster tetap tersimpan apa adanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_ledger_cluster_splits', function (Blueprint $table) {
            $table->foreignId('cluster_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Baris tanpa cluster tidak bisa dikembalikan jadi NOT NULL tanpa mengarang nilai,
        // jadi baris itu dibuang dulu agar rollback-nya benar-benar bisa jalan.
        \Illuminate\Support\Facades\DB::table('bs_ledger_cluster_splits')->whereNull('cluster_id')->delete();

        Schema::table('bs_ledger_cluster_splits', function (Blueprint $table) {
            $table->foreignId('cluster_id')->nullable(false)->change();
        });
    }
};

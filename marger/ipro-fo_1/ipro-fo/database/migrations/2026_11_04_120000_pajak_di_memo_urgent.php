<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rincian pajak Memo Urgent — sama dengan tahap 2 Registrasi Invoice Hutang. Nominal memo (amount)
 * = Dibayar (net) hasil hitungan ini; saat memo ditarik ke invoice, rincian ini mengisi & mengunci
 * tahap 2. Memo lama: dpp kosong (tanpa rincian) → invoice seperti sebelumnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memo_internals', function (Blueprint $t): void {
            $t->decimal('dpp', 18, 2)->nullable()->after('amount');   // Nilai Transaksi yang diketik
            $t->string('ppn_mode', 8)->nullable()->after('dpp');      // none | with
            $t->decimal('ppn_pct', 5, 2)->nullable()->after('ppn_mode');
            $t->boolean('ppn_incl')->default(false)->after('ppn_pct');
            $t->decimal('ppn_base', 18, 2)->nullable()->after('ppn_incl');
            $t->string('pph_mode', 10)->nullable()->after('ppn_base'); // none | withheld | borne
            $t->string('pph_type', 10)->nullable()->after('pph_mode');
            $t->decimal('pph_pct', 5, 2)->nullable()->after('pph_type');
            $t->decimal('pph_base', 18, 2)->nullable()->after('pph_pct');
        });
    }

    public function down(): void
    {
        Schema::table('memo_internals', fn (Blueprint $t) => $t->dropColumn(['dpp', 'ppn_mode', 'ppn_pct', 'ppn_incl', 'ppn_base', 'pph_mode', 'pph_type', 'pph_pct', 'pph_base']));
    }
};

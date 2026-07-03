<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tandai asal Cek/Giro Masuk (manual entri BO / kiriman FO Bilyet Giro) + referensi
 * idempotensi dari FO. Giro kiriman FO masuk status 'in_hand' TANPA jurnal
 * (receive_journal_id null) → menunggu "Posting GL" di Penerimaan Cek Giro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_giros', function (Blueprint $t): void {
            $t->string('source', 16)->default('manual')->after('status');   // manual / fo
            $t->string('source_ref', 64)->nullable()->after('source');       // idempotensi FO (mis. FO-BG-123)
            $t->index(['status', 'receive_journal_id']);
        });
    }

    public function down(): void
    {
        Schema::table('bs_giros', function (Blueprint $t): void {
            $t->dropIndex(['status', 'receive_journal_id']);
            $t->dropColumn(['source', 'source_ref']);
        });
    }
};

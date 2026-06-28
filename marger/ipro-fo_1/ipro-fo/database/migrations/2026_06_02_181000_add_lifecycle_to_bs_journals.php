<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Siklus jurnal ala eProperty: Baru (draft) → Final → Approved (posted) → Closed (periode).
 * Kolom status sudah ada; tambah jejak finalisasi & approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_journals', function (Blueprint $t): void {
            $t->timestamp('finalized_at')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bs_journals', function (Blueprint $t): void {
            $t->dropColumn(['finalized_at', 'approved_by']);
        });
    }
};

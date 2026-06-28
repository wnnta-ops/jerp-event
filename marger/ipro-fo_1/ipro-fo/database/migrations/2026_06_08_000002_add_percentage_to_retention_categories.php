<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #69 — Kategori Retensi: persentase default per kategori, flag DAJAM (pengurangan),
 * dan keterangan bebas ("DAJAM apa").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retention_categories', function (Blueprint $t): void {
            if (! Schema::hasColumn('retention_categories', 'percentage')) {
                $t->decimal('percentage', 5, 2)->default(0); // default %, lebar sama dgn bank_retention_terms
            }
            if (! Schema::hasColumn('retention_categories', 'is_dajam')) {
                $t->boolean('is_dajam')->default(false); // DAJAM = pengurangan, bukan retensi %
            }
            if (! Schema::hasColumn('retention_categories', 'keterangan')) {
                $t->text('keterangan')->nullable(); // free-text "DAJAM apa"
            }
        });
    }

    public function down(): void
    {
        Schema::table('retention_categories', function (Blueprint $t): void {
            foreach (['percentage', 'is_dajam', 'keterangan'] as $c) {
                if (Schema::hasColumn('retention_categories', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};

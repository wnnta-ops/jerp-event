<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dimensi analitik jurnal (faithful eProperty Struktur):
 *  - master Departemen / Ledger / Lokasi
 *  - kolom dimensi + mata uang & kurs per baris jurnal (bs_journal_lines)
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['bs_departments', 'bs_ledgers', 'bs_locations'] as $table) {
            if (! Schema::hasTable($table)) {
                Schema::create($table, function (Blueprint $t): void {
                    $t->id();
                    $t->foreignId('tenant_id')->constrained();
                    $t->string('code', 32);
                    $t->string('name');
                    $t->boolean('is_active')->default(true);
                    $t->timestamps();
                    $t->softDeletes();
                    $t->unique(['tenant_id', 'code']);
                });
            }
        }

        Schema::table('bs_journal_lines', function (Blueprint $t): void {
            if (! Schema::hasColumn('bs_journal_lines', 'department_id')) {
                $t->unsignedBigInteger('department_id')->nullable();
            }
            if (! Schema::hasColumn('bs_journal_lines', 'ledger_id')) {
                $t->unsignedBigInteger('ledger_id')->nullable();
            }
            if (! Schema::hasColumn('bs_journal_lines', 'location_id')) {
                $t->unsignedBigInteger('location_id')->nullable();
            }
            if (! Schema::hasColumn('bs_journal_lines', 'currency')) {
                $t->string('currency', 8)->default('IDR');
            }
            if (! Schema::hasColumn('bs_journal_lines', 'kurs')) {
                $t->decimal('kurs', 18, 6)->default(1);
            }
        });
    }

    public function down(): void
    {
        Schema::table('bs_journal_lines', function (Blueprint $t): void {
            foreach (['department_id', 'ledger_id', 'location_id', 'currency', 'kurs'] as $col) {
                if (Schema::hasColumn('bs_journal_lines', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
        Schema::dropIfExists('bs_locations');
        Schema::dropIfExists('bs_ledgers');
        Schema::dropIfExists('bs_departments');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SETTING KPA — meniru modul Setup eProperty:
 *  1. Kategori Retensi (retention_categories): master kategori retensi.
 *  2. Kategori Bank: identitas bank (perluasan bank_partners) + ketentuan retensi
 *     per bank (bank_retention_terms).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Perluas bank_partners dengan identitas bank (Cabang, No.Rek, dll) ──
        Schema::table('bank_partners', function (Blueprint $t): void {
            if (! Schema::hasColumn('bank_partners', 'project_id'))   $t->unsignedBigInteger('project_id')->nullable();
            if (! Schema::hasColumn('bank_partners', 'branch'))       $t->string('branch')->nullable();           // Cabang
            if (! Schema::hasColumn('bank_partners', 'account_no'))   $t->string('account_no', 64)->nullable();  // No. Rekening
            if (! Schema::hasColumn('bank_partners', 'account_name')) $t->string('account_name')->nullable(); // Atas Nama
            if (! Schema::hasColumn('bank_partners', 'address'))      $t->string('address', 255)->nullable(); // Alamat
            if (! Schema::hasColumn('bank_partners', 'city'))         $t->string('city', 100)->nullable();      // Kota
            if (! Schema::hasColumn('bank_partners', 'notes'))        $t->string('notes', 255)->nullable();        // Keterangan
        });

        // ── Master Kategori Retensi ──
        Schema::create('retention_categories', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('project_id')->nullable();
            $t->string('code', 64);
            $t->string('name');
            $t->unsignedInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['tenant_id', 'code']);
        });

        // ── Ketentuan retensi per bank ──
        Schema::create('bank_retention_terms', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('bank_partner_id')->constrained()->cascadeOnDelete();
            $t->foreignId('retention_category_id')->constrained()->cascadeOnDelete();
            $t->decimal('percentage', 5, 2)->default(0);  // % retensi
            $t->decimal('amount', 20, 2)->nullable();      // nominal tetap (opsional)
            $t->string('notes', 255)->nullable();
            $t->timestamps();
            $t->unique(['bank_partner_id', 'retention_category_id'], 'bank_retention_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_retention_terms');
        Schema::dropIfExists('retention_categories');

        Schema::table('bank_partners', function (Blueprint $t): void {
            foreach (['project_id', 'branch', 'account_no', 'account_name', 'address', 'city', 'notes'] as $col) {
                if (Schema::hasColumn('bank_partners', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};

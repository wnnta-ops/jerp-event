<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori Invoice boleh dipakai di BEBERAPA modul sekaligus (Invoice Hutang, Reimburse,
 * Deposit), dan tiap modul punya posisi kredit + akun hutangnya sendiri — diatur user.
 *
 *   bs_ap_category_usages   satu baris per (kategori, modul): posisi kredit & akun kreditnya
 *   bs_ap_invoices.credit_position   posisi kredit yang DIBEKUKAN saat invoice disimpan
 *
 * Kategori lama menjadi satu pemakaian: modul, posisi, dan akunnya disalin apa adanya, jadi
 * jurnal berikutnya sama persis dengan sebelum perubahan ini.
 *
 * credit_position invoice sengaja TIDAK diisi untuk invoice lama (perubahan berlaku ke depan):
 * yang kosong tetap dibaca dengan cara lama oleh PosisiHutang.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bs_ap_category_usages')) {
            Schema::create('bs_ap_category_usages', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained();
                $t->foreignId('category_id')->constrained('bs_ap_categories')->cascadeOnDelete();
                $t->string('jenis', 20);
                $t->string('credit_position', 20)->default('hutang');
                $t->unsignedBigInteger('payable_account_id');
                $t->timestamps();
                $t->unique(['category_id', 'jenis']);
                $t->index('jenis');
            });

            $sekarang = now();
            DB::table('bs_ap_categories')->orderBy('id')->get()->each(function ($c) use ($sekarang): void {
                DB::table('bs_ap_category_usages')->insert([
                    'tenant_id' => $c->tenant_id,
                    'category_id' => $c->id,
                    'jenis' => $c->jenis ?: 'invoice',
                    'credit_position' => $c->credit_position ?: 'hutang',
                    'payable_account_id' => $c->payable_account_id,
                    'created_at' => $sekarang,
                    'updated_at' => $sekarang,
                ]);
            });
        }

        if (! Schema::hasColumn('bs_ap_invoices', 'credit_position')) {
            Schema::table('bs_ap_invoices', function (Blueprint $t): void {
                $t->string('credit_position', 20)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bs_ap_invoices', 'credit_position')) {
            Schema::table('bs_ap_invoices', function (Blueprint $t): void {
                $t->dropColumn('credit_position');
            });
        }
        Schema::dropIfExists('bs_ap_category_usages');
    }
};

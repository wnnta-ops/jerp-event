<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rantai GMC: Posting BO (FO) → Posting Invoice dari FO (BO) → Rincian Biaya Master Data KPR.
 *
 * 1. bs_ap_refund_queue membawa identitas kategori gimmick, pecahan akunnya, dan SPR/unit yang
 *    dibiayai. Sebelumnya antrian GMC hanya membawa dua akun hasil tebakan kode COA statis di
 *    IproApPoster, jadi setup Kategori Gimmick sama sekali tidak terpakai saat posting.
 * 2. bs_kpr_biaya_entries menyimpan biaya yang SUDAH terbit invoice-nya per (booking, kolom
 *    biaya) — inilah sumber kolom Rincian Biaya di Master Data KPR, yang selama ini kosong
 *    kecuali SSP/BPHTB dari realisasi pencairan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_ap_refund_queue', function (Blueprint $t): void {
            $t->unsignedBigInteger('gimmick_category_id')->nullable()->after('refund_source');
            $t->string('kpr_biaya_key', 16)->nullable()->after('gimmick_category_id');
            $t->unsignedBigInteger('booking_id')->nullable()->after('kpr_biaya_key');   // ipro_bo.bookings
            $t->unsignedBigInteger('unit_id')->nullable()->after('booking_id');          // ipro_bo.units
            // Pecahan akun hasil Kategori Gimmick: [{side,account_id,portion}] — dipakai saat
            // menerbitkan invoice agar jurnalnya multi-baris sesuai setup.
            $t->json('account_split')->nullable()->after('offset_account_id');
            $t->index('booking_id');
        });

        Schema::create('bs_kpr_biaya_entries', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('booking_id');
            $t->string('biaya_key', 16);                    // kpr | ht | ppjb | ssp | bphtb | ajb
            $t->date('tgl');
            $t->decimal('nominal', 20, 2)->default(0);
            $t->string('source_type', 24);                  // gmc | ...
            $t->string('source_ref', 64)->nullable();       // no. GMC / referensi sumber
            $t->unsignedBigInteger('source_id')->nullable();
            $t->unsignedBigInteger('ap_invoice_id')->nullable();
            $t->unsignedBigInteger('gimmick_category_id')->nullable();
            $t->string('description', 255)->nullable();
            $t->timestamps();
            // Idempoten: satu sumber (mis. satu baris antrian GMC) hanya boleh sekali tercatat.
            $t->unique(['tenant_id', 'source_type', 'source_ref'], 'bs_kpr_biaya_source_unique');
            $t->index(['booking_id', 'biaya_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_kpr_biaya_entries');
        Schema::table('bs_ap_refund_queue', function (Blueprint $t): void {
            $t->dropIndex(['booking_id']);
            $t->dropColumn(['gimmick_category_id', 'kpr_biaya_key', 'booking_id', 'unit_id', 'account_split']);
        });
    }
};

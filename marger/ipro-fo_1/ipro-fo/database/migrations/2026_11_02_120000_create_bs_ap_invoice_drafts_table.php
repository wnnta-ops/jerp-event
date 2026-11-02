<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Draft Registrasi Invoice Hutang — satu per user per jenis.
 *
 * Dipakai saat user menekan "Edit" pada berkas pilihannya di langkah Detil Tagihan: isian wizard
 * disimpan di sini, user dibawa ke Unggah Berkas, lalu wizard dipulihkan saat dibuka lagi.
 * Dihapus otomatis begitu invoicenya tersimpan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bs_ap_invoice_drafts')) {
            return;
        }
        Schema::create('bs_ap_invoice_drafts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->nullable()->index();
            $t->unsignedBigInteger('user_id');
            $t->string('jenis', 20)->default('invoice');
            $t->unsignedBigInteger('berkas_upload_id')->nullable()->index();
            $t->unsignedTinyInteger('step')->default(1);
            $t->json('data');
            $t->timestamps();
            $t->unique(['user_id', 'jenis']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_ap_invoice_drafts');
    }
};

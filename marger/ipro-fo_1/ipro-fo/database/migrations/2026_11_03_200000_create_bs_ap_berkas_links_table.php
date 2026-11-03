<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link berkas supplier (Registrasi Invoice Hutang › Detil Tagihan › "Generate Link"): link tanpa
 * login yang dikirim ke supplier untuk mengunggah kelengkapan dokumen. Membawa nomor invoice yang
 * sudah dipesan (belum aktif ke mana-mana) dan isian wizard (draft) sampai invoicenya disimpan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bs_ap_berkas_links')) {
            return;
        }
        Schema::create('bs_ap_berkas_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('token', 64)->unique();
            $table->unsignedBigInteger('user_id')->index();          // pembuat invoice (pengunggah berkas)
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('invoice_number', 80)->unique();         // nomor invoice yang dipesan
            $table->json('data')->nullable();                       // isian wizard
            $table->unsignedTinyInteger('step')->default(3);
            $table->unsignedBigInteger('berkas_upload_id')->nullable()->index();
            $table->string('status', 20)->default('menunggu');      // menunggu | terisi | dipakai | batal
            $table->timestamp('filled_at')->nullable();
            $table->timestamp('seen_at')->nullable();               // dot di daftar invoice hilang setelah dibuka
            $table->unsignedBigInteger('ap_invoice_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_ap_berkas_links');
    }
};

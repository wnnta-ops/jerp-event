<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indeks untuk penautan dokumen sumber pada Invoice Hutang.
 *
 * bs_ap_invoices.source_type + source_id adalah satu-satunya tautan antara invoice dan
 * dokumen sumbernya (PO, SPK, refund, batch), tetapi tidak pernah ada indeks di atasnya.
 * Selama ini pencariannya menyapu seluruh tabel — sudah begitu di Posting Invoice, dan
 * daftar Purchase Order kini ikut membacanya untuk menampilkan kolom Invoice Hutang.
 *
 * Daftar PO SENGAJA tanpa paginasi (seluruh baris dikirim ke klien agar ringkasan dan
 * saringannya dihitung di sana), jadi halaman itu memang sudah berat; menambahkan satu
 * penyapuan tabel penuh di atasnya adalah biaya yang tidak perlu dibayar siapa pun.
 *
 * Query pembacanya hanya satu per permintaan, jadi tanpa indeks ini pun halamannya tetap
 * benar — yang berubah cuma ongkosnya, dan itulah alasan indeks ini berdiri sendiri
 * dan aman dijalankan kapan saja.
 */
return new class extends Migration
{
    private const NAMA = 'bs_ap_invoices_source_idx';

    public function up(): void
    {
        if (! Schema::hasTable('bs_ap_invoices') || $this->sudahAda()) {
            return;
        }

        Schema::table('bs_ap_invoices', function (Blueprint $table) {
            // source_type didahulukan: ia yang menyaring paling banyak (nilainya sedikit dan
            // selalu diketahui), sedangkan source_id dibaca sesudahnya untuk pengelompokan.
            $table->index(['source_type', 'source_id'], self::NAMA);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bs_ap_invoices') || ! $this->sudahAda()) {
            return;
        }

        Schema::table('bs_ap_invoices', function (Blueprint $table) {
            $table->dropIndex(self::NAMA);
        });
    }

    private function sudahAda(): bool
    {
        return collect(Schema::getIndexes('bs_ap_invoices'))
            ->contains(fn ($i) => ($i['name'] ?? null) === self::NAMA);
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `booking_id` pada penanda rekom batal menjadi BOLEH KOSONG.
 *
 * Tabel ini lahir untuk modul KPR, dan di sana setiap baris SELALU punya booking:
 * inisiasi/pengajuan KPR memang tumbuh dari sebuah booking. Kini layar "Rekom Batal Cash"
 * di Front Office ikut menulis ke sini, dan syarat itu tidak berlaku lagi — SPR cash hidup
 * di fo_kontrak, sedangkan barisan `bookings` di database ini adalah CERMINAN yang dibuat
 * IproMasterSync::syncSpr() dan cerminan itu hanya terbentuk bila master customer & unit
 * sudah tersinkron lebih dulu.
 *
 * Tanpa pelonggaran ini kegagalannya berbentuk paling buruk yang mungkin: memo rekom batal
 * TERCETAK di layar operator, tetapi penulisan penandanya kena galat FK 1452 — sehingga
 * tombol "Ajukan Pembatalan" yang seharusnya terbuka tetap mati, tanpa satu pun tanda di
 * layar bahwa ada yang gagal.
 *
 * FK-nya SENGAJA DIPERTAHANKAN: MySQL melewati pemeriksaan FK untuk nilai NULL, jadi baris
 * cash tanpa cerminan tetap bisa masuk sementara baris yang MENYEBUT booking tetap dijamin
 * menunjuk booking yang benar-benar ada.
 *
 * Dipakai ALTER mentah, bukan ->change(): ->change() menulis ulang seluruh definisi kolom
 * dari Blueprint, dan definisi di sini tidak memuat FK-nya — pada beberapa versi itu berarti
 * indeks/FK-nya ikut ditulis ulang atau hilang diam-diam.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kpr_cancel_recommendations')) {
            return;
        }

        if ($this->bolehKosong()) {
            return;
        }

        DB::statement('ALTER TABLE `kpr_cancel_recommendations` MODIFY `booking_id` BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('kpr_cancel_recommendations') || ! $this->bolehKosong()) {
            return;
        }

        // Baris cash TIDAK punya booking; membiarkannya di sini membuat ALTER gagal dengan
        // galat yang membingungkan ("Invalid use of NULL value"). Dibuang lebih dulu supaya
        // mundurnya benar-benar bisa dijalankan — penanda cash memang lahir dari fitur yang
        // sedang dimundurkan, jadi tidak ada data lain yang ikut hilang.
        DB::table('kpr_cancel_recommendations')->whereNull('booking_id')->delete();

        DB::statement('ALTER TABLE `kpr_cancel_recommendations` MODIFY `booking_id` BIGINT UNSIGNED NOT NULL');
    }

    /**
     * Dibaca dari information_schema, bukan `SHOW COLUMNS ... LIKE ?`: SHOW tidak menerima
     * parameter terikat, sehingga tanda tanyanya sampai ke MySQL apa adanya dan migrasinya
     * berhenti dengan galat sintaks 1064 sebelum sempat menyentuh tabelnya.
     */
    private function bolehKosong(): bool
    {
        $kolom = DB::selectOne(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS'
            .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['kpr_cancel_recommendations', 'booking_id'],
        );

        return $kolom !== null && strtoupper((string) ($kolom->IS_NULLABLE ?? 'NO')) === 'YES';
    }
};

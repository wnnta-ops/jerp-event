<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Master "Kategori Reject Bank" (KPR → Set Up) — daftar alasan penolakan KPR oleh bank.
 *
 * Sebelum ini alasan penolakan hanyalah teks bebas yang diketik di kotak prompt
 * ("Alasan penolakan (opsional)") lalu disimpan apa adanya ke kpr_applications.reject_reason,
 * sehingga saringan "Alasan Penolakan" pada laporan Reject Proses KPR hanya mengelompokkan
 * string persis: salah ketik satu huruf sudah menjadi kelompok tersendiri.
 */
return new class extends Migration
{
    /** Isi awal — sesuai daftar yang diminta, urut seperti yang disebutkan. */
    private const ISI_AWAL = [
        'RPC KURANG',
        'DATA DIRAGUKAN',
        'TIDAK DAPAT UNIT',
        'TIDAK BANKABLE (MAYORITAS PENGHASILAN TUNAI)',
        'BATAL SEPIHAK',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('kpr_bank_reject_categories')) {
            Schema::create('kpr_bank_reject_categories', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained();
                $t->string('name');
                $t->unsignedInteger('sort_order')->default(0);
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->softDeletes();
            });
        }

        // Penyemaian SENGAJA di luar blok pembuatan tabel dan memeriksa baris yang sudah ada.
        // Bila keduanya disatukan — seperti pada master sebelah — tabel yang terlanjur ada
        // membuat migrasi berhenti lebih awal, isinya tidak pernah masuk, dan migrasinya tetap
        // dilaporkan sukses. Bentuk ini juga aman dijalankan ulang.
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $sort = 1;
            foreach (self::ISI_AWAL as $name) {
                $sudahAda = DB::table('kpr_bank_reject_categories')
                    ->where('tenant_id', $tenantId)->where('name', $name)->exists();
                if (! $sudahAda) {
                    DB::table('kpr_bank_reject_categories')->insert([
                        'tenant_id' => $tenantId, 'name' => $name, 'sort_order' => $sort, 'is_active' => true,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $sort++;
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kpr_bank_reject_categories');
    }
};

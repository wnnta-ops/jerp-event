<?php

declare(strict_types=1);

use App\Domain\BusinessSupport\Services\BoNumberService;
use App\Domain\BusinessSupport\Support\BoDocumentNumbers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Numerator khusus Back Office.
 *
 * Sampai sekarang master `numerators` hanya memuat 17 dokumen Front Office; seluruh
 * penomoran Back Office ditulis mati di kode (JournalService, StockService, dan belasan
 * service lain). Akibatnya formatnya tidak bisa dilihat maupun diubah tanpa mengubah kode.
 *
 * Kolom `scope` memisahkan kedua daftar itu. Dipisah, BUKAN dicampur, karena keduanya dibaca
 * dua layar berbeda dan kode yang kebetulan sama (mis. FP) tidak boleh berbagi satu deret.
 *
 * NOMOR URUT AWAL DITANAM DARI DOKUMEN YANG SUDAH ADA — ini bagian terpentingnya. Bila
 * seluruh baris dimulai dari nol, nomor berikutnya akan mengulang nomor yang sudah terbit;
 * dan karena hampir semua kolom nomor dokumen ber-UNIQUE, penyimpanan berikutnya akan
 * GAGAL — bukan menghasilkan data ganda, tetapi menolak dokumen yang sah.
 *
 * Format bawaannya sengaja menghasilkan nomor yang SAMA PERSIS dengan kode sebelumnya:
 * migrasi ini membuat formatnya bisa dilihat & diubah, bukan mengubah nomornya hari ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('numerators', 'scope')) {
            Schema::table('numerators', function (Blueprint $t): void {
                // Seluruh baris yang sudah ada adalah dokumen Front Office.
                $t->string('scope', 8)->default('fo')->after('project_id')
                    ->comment('fo = dokumen Front Office, bo = Back Office');
            });
        }

        $indeks = collect(Schema::getIndexes('numerators'))->pluck('name');

        // Indeks BARU dibuat lebih dulu, baru yang lama dilepas. Foreign key tenant_id
        // bersandar pada indeks lama (tenant_id ada di posisi paling kiri), dan MySQL menolak
        // melepasnya selama tidak ada indeks lain yang bisa menggantikan perannya.
        if (! $indeks->contains('numerators_tenant_project_scope_code_unique')) {
            Schema::table('numerators', function (Blueprint $t): void {
                $t->unique(['tenant_id', 'project_id', 'scope', 'code'], 'numerators_tenant_project_scope_code_unique');
            });
        }
        if ($indeks->contains('numerators_tenant_id_project_id_code_unique')) {
            Schema::table('numerators', function (Blueprint $t): void {
                $t->dropUnique('numerators_tenant_id_project_id_code_unique');
            });
        }

        // Tanam katalog Back Office untuk setiap tenant yang ada.
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            BoNumberService::tanamMaster((int) $tenantId);
        }
    }

    public function down(): void
    {
        DB::table('numerators')->where('scope', BoDocumentNumbers::SCOPE)->delete();

        if (! Schema::hasColumn('numerators', 'scope')) {
            return;
        }

        Schema::table('numerators', function (Blueprint $t): void {
            $t->dropUnique('numerators_tenant_project_scope_code_unique');
            $t->dropColumn('scope');
        });

        Schema::table('numerators', function (Blueprint $t): void {
            $t->unique(['tenant_id', 'project_id', 'code'], 'numerators_tenant_id_project_id_code_unique');
        });
    }
};

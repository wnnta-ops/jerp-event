<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tautan akta AJB dari modul Legal (app/legal) ke Detail AJB (Booked Revenue).
 *
 * Sampai sekarang baris ajb_documents hanya lahir di BO sendiri lewat "Input Massal AJB",
 * dan layar Detail AJB menyembunyikan kolom akta selama unitnya belum punya dokumen BAST.
 * Aturan itu masuk akal selama nomor akta hanya diketik massal tanpa dokumen di belakangnya.
 *
 * Sekarang aktanya diarsipkan di modul Legal — lengkap dengan berkas pindaiannya — lalu
 * ditautkan ke No. SPR (Final). Itu bukti yang lebih kuat daripada baris ketikan, jadi
 * `source` membedakan keduanya: baris 'legal' berhak menampilkan kolom aktanya sendiri
 * (lihat BookedRevenueController::ajbRows), baris 'bo' tetap menunggu BAST seperti semula.
 * Tanpa kolom pembeda ini, satu-satunya cara memenuhi permintaan adalah mencabut syarat
 * BAST untuk SEMUA baris — termasuk yang memang belum punya dokumen apa pun.
 *
 * `legal_doc_id` = id dokumen di database modul Legal (cuid). Unik: satu dokumen Legal
 * hanya boleh menempati satu baris SPR, supaya akta yang sama tidak muncul di dua baris.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ajb_documents', 'source')) {
            return;
        }

        Schema::table('ajb_documents', function (Blueprint $t): void {
            // Bawaannya 'bo': seluruh baris yang sudah ada memang terbit di Back Office,
            // jadi tidak satu pun berubah perilakunya tanpa diminta.
            $t->string('source', 16)->default('bo')->after('status');
            $t->string('legal_doc_id', 64)->nullable()->after('source');
            $t->timestamp('legal_synced_at')->nullable()->after('legal_doc_id');

            $t->unique('legal_doc_id', 'ajb_doc_legal_unik');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('ajb_documents', 'source')) {
            return;
        }

        Schema::table('ajb_documents', function (Blueprint $t): void {
            $t->dropUnique('ajb_doc_legal_unik');
            $t->dropColumn(['source', 'legal_doc_id', 'legal_synced_at']);
        });
    }
};

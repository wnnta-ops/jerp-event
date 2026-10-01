<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pemetaan Project kita ↔ "proyek" MUK ERP.
 *
 * SATU ARAH BANYAK, bukan satu kolom kode. "Proyek" di MUK ternyata PAKET PEKERJAAN, bukan
 * proyek properti: 95 proyek untuk 95 kontrak, hampir satu-satu. Empat di antaranya —
 * "PROYEK HOUSING CLUSTER 3 TAHAP 4 BATCH I", "INFRA CLUSTER 3 TAHAP 4", "PROYEK HOUSING
 * CLUSTER 3 TAHAP 4 BATCH 2", "KANOPI CARPORT CLUSTER 3 TAHAP 3 & 4" — semuanya milik SATU
 * project kita, C3T4. Satu kolom "kode project MUK" hanya menampung salah satunya dan
 * diam-diam membuang sisanya beserta biayanya.
 *
 * DICOCOKKAN MANUSIA, bukan oleh kecocokan teks. Kode kita (C3T4) dan kode MUK (083, 085,
 * 088, 096) tidak punya hubungan apa pun, dan namanya pun berbeda susunan. Menebak lewat
 * kemiripan nama berarti sebagian paket diam-diam tidak terpasang — dan yang hilang adalah
 * biaya, bukan sekadar baris.
 *
 * DISIMPAN SEBAGAI KODE, bukan foreign key ke bs_muk_contracts. Salinan kontrak itu dibuang
 * dan ditarik ulang setiap kali MUK membangun ulang datanya — pernah terjadi 1 Oktober 2026,
 * dan seluruh muk_id bergeser. Kode proyek bertahan melewati itu; id tidak.
 *
 * UNIK PER KODE MUK: satu paket pekerjaan hanya boleh menempel pada SATU project kita. Kalau
 * boleh ganda, biaya paket itu terhitung dua kali di dua project sekaligus, dan tidak ada
 * galat yang menandainya — hanya dua angka yang sama-sama tampak masuk akal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_project_muk', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->unsignedBigInteger('project_id');

            $t->string('muk_proyek_kode', 32);
            // Nama ikut disalin supaya layar tetap terbaca ketika salinan kontrak sedang
            // kosong — mis. tepat setelah dibuang dan belum ditarik ulang.
            $t->string('muk_proyek_nama', 191)->nullable();

            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();

            $t->unique(['tenant_id', 'muk_proyek_kode'], 'bs_project_muk_kode_unik');
            $t->index(['project_id']);
            $t->foreign('project_id')->references('id')->on('bs_projects')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_project_muk');
    }
};

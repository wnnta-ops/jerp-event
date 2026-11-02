<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dept Head berkas kini otomatis dari atasan pengunggah (Employee Center). Draft boleh tersimpan
 * walau atasannya belum diatur — dept_head_id baru dipastikan terisi saat diajukan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_ap_berkas_uploads', function (Blueprint $t): void {
            $t->unsignedBigInteger('dept_head_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Tidak dikembalikan ke NOT NULL: draft tanpa Dept Head mungkin sudah ada.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Draft wizard "Buat event" — satu per akun. Isi form disimpan apa adanya
 * (belum divalidasi) supaya bisa dilanjutkan nanti oleh akun yang sama.
 * Dihapus otomatis begitu event berhasil dibuat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('et_setup_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('data');
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('et_setup_drafts');
    }
};

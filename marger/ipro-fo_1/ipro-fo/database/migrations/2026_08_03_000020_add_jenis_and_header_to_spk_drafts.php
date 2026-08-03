<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Set Up SPK jadi PER JENIS SPK (Housing / Infrastruktur / Lainnya) — mengikuti draft
 * asli yang isinya beda pada judul pekerjaan, mukadimah, dan lingkup Pasal 1.
 * Baris lama (jenis NULL) dipertahankan sebagai template fallback untuk semua jenis.
 *
 * Ditambah kepala dokumen yang selama ini tidak bisa diatur: judul pekerjaan,
 * mukadimah ("Bahwa Pihak Pertama selaku Developer…"), dan paragraf kedudukan para pihak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_spk_drafts', function (Blueprint $t): void {
            $t->string('jenis', 30)->nullable()->after('tenant_id');
            $t->string('proyek_line', 150)->nullable()->after('subjudul');
            $t->string('judul_pekerjaan', 200)->nullable()->after('proyek_line');
            $t->text('mukadimah')->nullable()->after('pembukaan');
            $t->text('pihak1_teks')->nullable()->after('mukadimah');
            $t->text('pihak2_teks')->nullable()->after('pihak1_teks');
        });

        // Unique baru dibuat DULU: tenant_id jadi kolom terkiri sehingga tetap
        // menopang foreign key saat unique lama dilepas (syarat MySQL).
        Schema::table('bs_spk_drafts', function (Blueprint $t): void {
            $t->unique(['tenant_id', 'jenis'], 'bs_spk_drafts_tenant_jenis_unique');
        });
        Schema::table('bs_spk_drafts', function (Blueprint $t): void {
            $t->dropUnique('bs_spk_drafts_tenant_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('bs_spk_drafts', function (Blueprint $t): void {
            $t->unique('tenant_id', 'bs_spk_drafts_tenant_id_unique');
        });
        Schema::table('bs_spk_drafts', function (Blueprint $t): void {
            $t->dropUnique('bs_spk_drafts_tenant_jenis_unique');
            $t->dropColumn(['jenis', 'proyek_line', 'judul_pekerjaan', 'mukadimah', 'pihak1_teks', 'pihak2_teks']);
        });
    }
};

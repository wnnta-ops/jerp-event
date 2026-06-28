<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — Customer Expansion (+30 field, 7 sections).
 *
 * Sections:
 *   1. Identitas — project_id, data_source_id, nationality
 *   2. Kartu Identitas — rt_rw, kelurahan, kecamatan, id_expires_at, id_lifetime
 *   3. Data Pribadi — religion
 *   4. NPWP — npwp_name, npwp_same_as_ktp + 6 alamat fields
 *   5. Kontak — home_phone, fax
 *   6. Surat Menyurat — mailing_same_as_ktp + 6 alamat fields
 *   7. Pekerjaan — job_title + office_phone + 6 alamat fields
 *   8. Emergency Contact — name, relationship, phone, email
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            // ── 1. Identitas ──────────────────────────────
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('data_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('nationality', 3)->default('WNI');

            // ── 2. Kartu Identitas (KTP structured address) ──
            $table->string('address_rt_rw', 20)->nullable();
            $table->string('kelurahan', 100)->nullable();
            $table->string('kecamatan', 100)->nullable();
            $table->date('id_expires_at')->nullable();
            $table->boolean('id_lifetime')->default(false);

            // ── 3. Data Pribadi ──
            $table->string('religion', 20)->nullable();

            // ── 4. NPWP (extended) ──
            $table->string('npwp_name', 150)->nullable();
            $table->boolean('npwp_same_as_ktp')->default(true);
            $table->string('npwp_address', 255)->nullable();
            $table->string('npwp_rt_rw', 20)->nullable();
            $table->string('npwp_kelurahan', 100)->nullable();
            $table->string('npwp_kecamatan', 100)->nullable();
            $table->string('npwp_kota', 100)->nullable();
            $table->string('npwp_postal_code', 10)->nullable();

            // ── 5. Kontak (extended) ──
            $table->string('home_phone', 30)->nullable();
            $table->string('fax', 30)->nullable();

            // ── 6. Alamat Surat Menyurat ──
            $table->boolean('mailing_same_as_ktp')->default(true);
            $table->string('mailing_address', 255)->nullable();
            $table->string('mailing_rt_rw', 20)->nullable();
            $table->string('mailing_kelurahan', 100)->nullable();
            $table->string('mailing_kecamatan', 100)->nullable();
            $table->string('mailing_kota', 100)->nullable();
            $table->string('mailing_postal_code', 10)->nullable();

            // ── 7. Data Pekerjaan (extended) ──
            $table->string('job_title', 100)->nullable();
            $table->string('office_phone', 30)->nullable();
            $table->string('office_address', 255)->nullable();
            $table->string('office_rt_rw', 20)->nullable();
            $table->string('office_kelurahan', 100)->nullable();
            $table->string('office_kecamatan', 100)->nullable();
            $table->string('office_kota', 100)->nullable();
            $table->string('office_postal_code', 10)->nullable();

            // ── 8. Emergency Contact ──
            $table->string('emergency_name', 150)->nullable();
            $table->string('emergency_relationship', 20)->nullable();
            $table->string('emergency_phone', 30)->nullable();
            $table->string('emergency_email', 150)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            // FK drops (only if exists)
            if (Schema::hasColumn('customers', 'project_id')) {
                $table->dropForeign(['project_id']);
            }
            if (Schema::hasColumn('customers', 'data_source_id')) {
                $table->dropForeign(['data_source_id']);
            }

            $table->dropColumn([
                // 1. Identitas
                'project_id', 'data_source_id', 'nationality',
                // 2. Kartu Identitas
                'address_rt_rw', 'kelurahan', 'kecamatan', 'id_expires_at', 'id_lifetime',
                // 3. Data Pribadi
                'religion',
                // 4. NPWP
                'npwp_name', 'npwp_same_as_ktp', 'npwp_address',
                'npwp_rt_rw', 'npwp_kelurahan', 'npwp_kecamatan', 'npwp_kota', 'npwp_postal_code',
                // 5. Kontak
                'home_phone', 'fax',
                // 6. Surat Menyurat
                'mailing_same_as_ktp', 'mailing_address',
                'mailing_rt_rw', 'mailing_kelurahan', 'mailing_kecamatan', 'mailing_kota', 'mailing_postal_code',
                // 7. Pekerjaan
                'job_title', 'office_phone', 'office_address',
                'office_rt_rw', 'office_kelurahan', 'office_kecamatan', 'office_kota', 'office_postal_code',
                // 8. Emergency Contact
                'emergency_name', 'emergency_relationship', 'emergency_phone', 'emergency_email',
            ]);
        });
    }
};

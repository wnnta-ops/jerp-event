<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_customers', function (Blueprint $table) {
            // Alamat KTP detail (PRD §3.1.1)
            $table->string('jalan')->nullable()->after('address');
            $table->string('rt_rw', 16)->nullable()->after('jalan');
            $table->string('kelurahan')->nullable()->after('rt_rw');
            $table->string('kecamatan')->nullable()->after('kelurahan');

            // Alamat surat-menyurat (PRD §3.1.1)
            $table->boolean('mail_same_as_ktp')->default(true)->after('postal_code');
            $table->text('mail_address')->nullable()->after('mail_same_as_ktp');
            $table->string('mail_city')->nullable()->after('mail_address');
            $table->string('mail_province')->nullable()->after('mail_city');
            $table->string('mail_postal_code', 16)->nullable()->after('mail_province');

            // Data pasangan (kondisional saat menikah)
            $table->string('spouse_full_name')->nullable()->after('mail_postal_code');
            $table->string('spouse_nik', 32)->nullable()->after('spouse_full_name');
            $table->string('spouse_email')->nullable()->after('spouse_nik');
            $table->string('spouse_phone', 32)->nullable()->after('spouse_email');
            $table->string('spouse_birth_place')->nullable()->after('spouse_phone');
            $table->date('spouse_birth_date')->nullable()->after('spouse_birth_place');
            $table->string('spouse_occupation')->nullable()->after('spouse_birth_date');
            $table->boolean('spouse_address_same_as_pemohon')->default(true)->after('spouse_occupation');
            $table->text('spouse_address')->nullable()->after('spouse_address_same_as_pemohon');

            // File paths (storage::disk)
            $table->string('ktp_file_path')->nullable()->after('spouse_address');
            $table->string('npwp_file_path')->nullable()->after('ktp_file_path');
            $table->string('ktp_pasangan_file_path')->nullable()->after('npwp_file_path');

            // Sumber data / iklan (PRD §3.1.1)
            $table->string('sumber_data', 64)->nullable()->after('ktp_pasangan_file_path');

            // Lead status — distinguishes NUP public registration vs internal customer
            $table->enum('lead_status', ['LEAD', 'PROSPEK', 'CUSTOMER'])->default('CUSTOMER')->after('sumber_data');
        });
    }

    public function down(): void
    {
        Schema::table('fo_customers', function (Blueprint $table) {
            $table->dropColumn([
                'jalan', 'rt_rw', 'kelurahan', 'kecamatan',
                'mail_same_as_ktp', 'mail_address', 'mail_city', 'mail_province', 'mail_postal_code',
                'spouse_full_name', 'spouse_nik', 'spouse_email', 'spouse_phone',
                'spouse_birth_place', 'spouse_birth_date', 'spouse_occupation',
                'spouse_address_same_as_pemohon', 'spouse_address',
                'ktp_file_path', 'npwp_file_path', 'ktp_pasangan_file_path',
                'sumber_data', 'lead_status',
            ]);
        });
    }
};

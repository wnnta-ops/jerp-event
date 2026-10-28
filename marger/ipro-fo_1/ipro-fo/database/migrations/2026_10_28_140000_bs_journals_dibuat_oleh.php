<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nama penginput jurnal dari eProperty (GlJurnal.Dibuat) — teks, bukan akun.
 *
 * Jurnal yang dibuat di J-ERP menyimpan pembuatnya di created_by. Jurnal hasil migrasi/sinkron
 * eProperty tidak punya akun J-ERP yang bisa ditunjuk — nama legacy belum tentu cocok dengan
 * user mana pun — jadi namanya disimpan apa adanya di sini. Diisi skrip ETL (etl/bo/10_coa_jurnal.sql).
 * Kolom "Diinput Oleh" pada Detail Transaksi Jurnal = createdBy.name, jatuh ke kolom ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bs_journals', 'dibuat_oleh')) {
            Schema::table('bs_journals', function (Blueprint $t): void {
                $t->string('dibuat_oleh', 150)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bs_journals', 'dibuat_oleh')) {
            Schema::table('bs_journals', function (Blueprint $t): void {
                $t->dropColumn('dibuat_oleh');
            });
        }
    }
};

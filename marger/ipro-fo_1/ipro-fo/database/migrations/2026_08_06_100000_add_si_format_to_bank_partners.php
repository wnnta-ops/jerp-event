<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Format SI (Surat Instruksi) per bank — Set Up KPR → Kategori Bank → tab "Format SI".
 *
 * Sebelumnya Cetak SI hanya punya tiga tombol tetap (BTN / BSN / BRI) yang harus dipilih manual
 * dan tidak ada kaitannya dengan bank pada KPR yang sedang dibuka. Dengan kolom ini tiap bank
 * menyimpan formatnya sendiri, jadi Cetak SI langsung memakai format bank ybs.
 *
 *  - si_format   : format bawaan yang dipakai (btn | bsn | bri).
 *  - si_template : format hasil impor/suntingan operator (HTML + placeholder {{...}}).
 *                  Bila terisi, format inilah yang dipakai — bawaan hanya jadi titik awal.
 */
return new class extends Migration
{
    /** Pemetaan awal kode bank → format bawaan (sesuai ketentuan yang berlaku saat ini). */
    private const DEFAULTS = [
        'BTN' => 'btn',
        'BSN' => 'bsn',
        'BRI' => 'bri',
        'BNI' => 'bri',
        'MDR' => 'bri',
    ];

    public function up(): void
    {
        Schema::table('bank_partners', function (Blueprint $t): void {
            $t->string('si_format', 16)->nullable()->after('notes');
            $t->longText('si_template')->nullable()->after('si_format');
        });

        foreach (self::DEFAULTS as $code => $format) {
            DB::table('bank_partners')->whereRaw('UPPER(code) = ?', [$code])->update(['si_format' => $format]);
        }
    }

    public function down(): void
    {
        Schema::table('bank_partners', function (Blueprint $t): void {
            $t->dropColumn(['si_format', 'si_template']);
        });
    }
};

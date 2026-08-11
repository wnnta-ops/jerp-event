<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Terapkan Format SI per bank berdasarkan KODE BANK (MDR → Mandiri, BNI → BNI, dst.).
 *
 * Migrasi sebelumnya mencocokkan dari NAMA bank; ini menegaskannya lewat kode yang lebih
 * stabil, sekaligus membuat penetapannya bisa dijalankan ulang kapan pun lewat
 * `php artisan db:seed --class=BankSiFormatSeeder`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (class_exists(\Database\Seeders\BankSiFormatSeeder::class)) {
            (new \Database\Seeders\BankSiFormatSeeder)->run();
        }
    }

    public function down(): void
    {
        // Penetapan format tidak dibatalkan: mengosongkannya justru membuat Cetak SI gagal.
    }
};

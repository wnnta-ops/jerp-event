<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reimburse Biaya Karyawan & Deposit Renovasi Customer dipindah dari AP › Master ke
 * AP › Generate Invoice (sidebar, katalog menu_permissions, dan matriks Grup Akses BO).
 * Yang di sini hanya label izin tampilnya — nama izin & pemegangnya tidak berubah, jadi
 * akses yang sudah diberikan tetap berlaku.
 */
return new class extends Migration
{
    private const LABEL = [
        'menu.bs.ap.reimburse.index' => ['Tampil: Master › Reimburse Biaya Karyawan', 'Tampil: Generate Invoice › Reimburse Biaya Karyawan'],
        'menu.bs.ap.deposit.index' => ['Tampil: Master › Deposit Renovasi Customer', 'Tampil: Generate Invoice › Deposit Renovasi Customer'],
    ];

    public function up(): void
    {
        foreach (self::LABEL as $nama => [, $baru]) {
            DB::table('permissions')->where('name', $nama)->update(['label' => $baru]);
        }
    }

    public function down(): void
    {
        foreach (self::LABEL as $nama => [$lama]) {
            DB::table('permissions')->where('name', $nama)->update(['label' => $lama]);
        }
    }
};

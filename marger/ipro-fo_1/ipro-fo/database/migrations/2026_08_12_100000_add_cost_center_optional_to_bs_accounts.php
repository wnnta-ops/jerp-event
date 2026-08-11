<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda akun yang BOLEH dijurnal tanpa cost center.
 *
 * Bawaannya false — seluruh akun tetap wajib cost center persis seperti sebelumnya. Hanya akun
 * yang ditandai true (lihat CoaTanpaCostCenterSeeder) yang boleh dikosongkan, dan pengosongan itu
 * tidak menghambat proses berikutnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_accounts', function (Blueprint $t): void {
            if (! Schema::hasColumn('bs_accounts', 'cost_center_optional')) {
                $t->boolean('cost_center_optional')->default(false)->after('is_active');
            }
        });

        // Chart of Account umumnya sudah terisi (EpropertyCoaSeeder) sebelum migrasi ini dijalankan,
        // jadi tandai sekarang juga. Pada instalasi baru yang CoA-nya dimuat belakangan, penandaan
        // dilakukan oleh CoaTanpaCostCenterSeeder — seeder itu idempoten & cocok dijalankan ulang.
        if (class_exists(\Database\Seeders\CoaTanpaCostCenterSeeder::class)) {
            (new \Database\Seeders\CoaTanpaCostCenterSeeder)->run();
        }
    }

    public function down(): void
    {
        Schema::table('bs_accounts', function (Blueprint $t): void {
            $t->dropColumn('cost_center_optional');
        });
    }
};

<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            TenantSeeder::class,
            RolesAndPermissionsSeeder::class,
            AccessMatrixSeeder::class,
            ApprovalPermissionsSeeder::class,
            InitialAdminSeeder::class,
            MasterDataSeeder::class,
            // BO (Back Office / Accounting)
            BoPermissionsSeeder::class,
            BoNumeratorSeeder::class,
            BoChartOfAccountSeeder::class,
        ]);
    }
}

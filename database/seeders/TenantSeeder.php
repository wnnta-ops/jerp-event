<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::updateOrCreate(
            ['id' => 1],
            [
                'code' => 'MUP',
                'name' => 'Java Residence (MUP)',
                'is_active' => true,
                'settings_json' => [
                    'locale' => 'id',
                    'timezone' => 'Asia/Jakarta',
                ],
            ]
        );
    }
}

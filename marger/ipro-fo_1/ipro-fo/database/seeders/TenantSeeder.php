<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Foundation\Tenant\Models\Tenant;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::updateOrCreate(
            ['code' => 'MUP'],
            [
                'name'         => 'PT. Mitra Usaha Propertindo',
                'domain'       => 'ipro-fo.mup.id',
                'settings'     => ['theme' => 'default'],
                'activated_at' => now(),
            ],
        );
    }
}

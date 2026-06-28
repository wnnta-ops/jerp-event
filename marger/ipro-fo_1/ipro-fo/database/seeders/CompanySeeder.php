<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Foundation\Tenant\Models\Company;
use App\Domain\Foundation\Tenant\Models\Tenant;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('code', 'MUP')->firstOrFail();

        Company::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'MUP'],
            [
                'name'    => 'PT. Mitra Usaha Propertindo',
                'npwp'    => '01.234.567.8-901.000',
                'phone'   => '+62 22 12345678',
                'email'   => 'info@mup.id',
                'address' => 'Jl. Soekarno-Hatta No. 1, Bandung',
            ],
        );
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * The 12 default roles per PRD §3.2. Granular permissions are seeded
     * per-module as each module ships (Phase 1+).
     *
     * @var array<int,string>
     */
    protected array $roles = [
        'super_admin',
        'direktur',
        'finance_manager',
        'finance_staff',
        'sales_manager',
        'sales_staff',
        'legal_staff',
        'kpr_officer',
        'collector',
        'kasir_launching',
        'it_admin',
        'auditor',
    ];

    public function run(): void
    {
        Artisan::call('permission:cache-reset');

        foreach ($this->roles as $name) {
            Role::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }
    }
}

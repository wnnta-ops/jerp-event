<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Foundation\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('code', 'MUP')->firstOrFail();

        $users = [
            ['name' => 'Super Admin', 'email' => 'admin@mup.local',          'role' => 'SuperAdmin'],
            ['name' => 'Rina Sales',  'email' => 'rina.sales@mup.local',     'role' => 'Sales'],
            ['name' => 'Joko Manager','email' => 'joko.smanager@mup.local',  'role' => 'SalesManager'],
            ['name' => 'Dewi Director','email' => 'dewi.sdirector@mup.local', 'role' => 'SalesDirector'],
            ['name' => 'Ani Finance', 'email' => 'ani.finance@mup.local',    'role' => 'Finance'],
            ['name' => 'Budi FinMgr', 'email' => 'budi.fmanager@mup.local',  'role' => 'FinanceManager'],
            ['name' => 'Citra Collec','email' => 'citra.collec@mup.local',   'role' => 'Collection'],
            ['name' => 'Direktur MUP','email' => 'direktur@mup.local',       'role' => 'Direktur'],
        ];

        foreach ($users as $u) {
            $user = User::firstOrCreate(
                ['email' => $u['email']],
                [
                    'tenant_id'           => $tenant->id,
                    'name'                => $u['name'],
                    'password'            => Hash::make('Admin@123'),
                    'is_active'           => true,
                    'email_verified_at'   => now(),
                    'password_changed_at' => now(),
                ],
            );
            $user->syncRoles([$u['role']]);
        }
    }
}

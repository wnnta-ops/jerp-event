<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Foundation\Tenant\Models\Tenant;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('code', 'MUP')->firstOrFail();

        $roles = [
            'SuperAdmin'      => ['description' => 'Akses penuh ke seluruh sistem', 'is_system' => true],
            'Admin'           => ['description' => 'Admin operasional', 'is_system' => true],
            'SalesManager'    => ['description' => 'Manager Sales — approve diskon ≤ 7%', 'is_system' => true],
            'SalesDirector'   => ['description' => 'Direktur Sales — approve diskon ≤ 12%', 'is_system' => true],
            'Sales'           => ['description' => 'Staff Sales — input customer, booking', 'is_system' => true],
            'FinanceManager'  => ['description' => 'Manager Finance — approve refund/void', 'is_system' => true],
            'Finance'         => ['description' => 'Staff Finance — input TTS, kuitansi', 'is_system' => true],
            'Collection'      => ['description' => 'Staff Collection — followup, denda, SP', 'is_system' => true],
            'Direktur'        => ['description' => 'Direktur — final approval', 'is_system' => true],
            'Agent'           => ['description' => 'Agent Marketing eksternal', 'is_system' => true],
            'Viewer'          => ['description' => 'Read-only', 'is_system' => true],
        ];

        foreach ($roles as $name => $attrs) {
            $role = Role::firstOrCreate(
                ['name' => $name, 'tenant_id' => $tenant->id, 'guard_name' => 'web'],
                $attrs,
            );

            // SuperAdmin → all permissions
            if ($name === 'SuperAdmin') {
                $role->syncPermissions(Permission::all());
                continue;
            }

            // Default sets per role:
            /*
             * IZIN MODUL YANG SUDAH PINDAH KE FO TIDAK LAGI DISEBUT DI SINI.
             *
             * Sales, Finance, Collection, Reporting & Approval dikerjakan sepenuhnya di FO;
             * rutenya di BO sudah dimatikan config/modul_pindah_fo.php dan izinnya sudah
             * lama berhenti dibuat PermissionSeeder. Yang tertinggal hanya penyebutan di
             * seeder ini — dan itu cukup untuk MENGHIDUPKANNYA KEMBALI: satu `db:seed`
             * membuat baris izinnya terbit lagi di layar Grup Akses BO, tanpa satu pun
             * menu yang bisa dibuka olehnya.
             *
             * `businesssupport.*` TETAP ada: itu izin Back Office sendiri, bukan FO.
             */
            $perms = match ($name) {
                'Sales' => [
                    'master.customer.view', 'master.customer.create', 'master.customer.update',
                    'master.unit.view',
                ],
                'SalesManager' => [
                    'master.customer.view', 'master.unit.view',
                ],
                'Finance' => [
                    'master.customer.view',
                    // #125: staf finance boleh akses Business Support & edit tgl terima dokumen bon sementara
                    'businesssupport.view', 'businesssupport.transaction.create',
                ],
                'FinanceManager' => [
                    'bankloan.setup.view', 'bankloan.setup.manage', 'bankloan.application.view',
                    'businesssupport.view', 'businesssupport.setup.manage', 'businesssupport.transaction.create', 'businesssupport.approval.act',
                ],
                'Collection' => [
                    'master.customer.view',
                ],
                'Direktur' => [
                    'master.customer.view', 'master.customer.view.sensitive',
                ],
                'Viewer' => [
                    'master.customer.view', 'master.unit.view',
                ],
                default => [],
            };

            $role->syncPermissions(
                Permission::whereIn('name', $perms)->get()
            );
        }
    }
}

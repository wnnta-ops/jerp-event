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
            $perms = match ($name) {
                'Sales' => [
                    'master.customer.view', 'master.customer.create', 'master.customer.update',
                    'master.unit.view', 'sales.hold.view', 'sales.hold.create',
                    'sales.booking.view', 'sales.booking.create', 'sales.booking.update',
                    'sales.discount.request', 'sales.nup.view', 'sales.nup.create',
                    'collection.followup.view', 'collection.followup.create',
                    'reporting.dashboard.view',
                ],
                'SalesManager' => [
                    'master.customer.view', 'master.unit.view',
                    'sales.booking.view', 'sales.booking.promote', 'sales.booking.cancel',
                    'sales.discount.approve', 'sales.contract.view',
                    'sales.scheme.view', 'sales.gimmick.view',
                    'sales.nup.view', 'sales.nup.verify', 'sales.nup.payment', 'sales.nup.refund',
                    'approval.view', 'approval.act',
                    'reporting.dashboard.view', 'reporting.report.view',
                ],
                'Finance' => [
                    'master.customer.view', 'finance.tts.view', 'finance.tts.create', 'finance.tts.allocate',
                    'finance.memo.view', 'finance.memo.create',
                    'finance.kuitansi.view', 'finance.kuitansi.issue',
                    'finance.va.view',
                    // #125: staf finance boleh akses Business Support & edit tgl terima dokumen bon sementara
                    'businesssupport.view', 'businesssupport.transaction.create',
                ],
                'FinanceManager' => [
                    'finance.tts.view', 'finance.tts.post', 'finance.tts.void',
                    'finance.memo.view', 'finance.kuitansi.view', 'finance.kuitansi.void',
                    'finance.cashback.view', 'finance.cashback.approve', 'finance.cashback.pay',
                    'finance.refund.view', 'finance.refund.approve', 'finance.refund.pay',
                    'bankloan.setup.view', 'bankloan.setup.manage', 'bankloan.application.view',
                    'businesssupport.view', 'businesssupport.setup.manage', 'businesssupport.transaction.create', 'businesssupport.approval.act',
                    'approval.view', 'approval.act',
                    'reporting.dashboard.view', 'reporting.report.view',
                ],
                'Collection' => [
                    'master.customer.view', 'collection.followup.view', 'collection.followup.create',
                    'collection.denda.view', 'collection.pjt.view',
                    'collection.tunggakan.view', 'collection.tunggakan.settle',
                    'collection.sp.view', 'collection.sp.issue',
                ],
                'Direktur' => [
                    'reporting.dashboard.view', 'reporting.dashboard.executive',
                    'reporting.report.view', 'reporting.report.export',
                    'approval.view', 'approval.act',
                    'master.customer.view', 'master.customer.view.sensitive',
                    'sales.discount.approve', 'sales.cancellation.approve',
                    'sales.gn.approve', 'sales.gu.approve', 'sales.reschedule.approve',
                ],
                'Viewer' => [
                    'master.customer.view', 'master.unit.view',
                    'sales.booking.view', 'finance.tts.view',
                    'reporting.dashboard.view',
                ],
                default => [],
            };

            $role->syncPermissions(
                Permission::whereIn('name', $perms)->get()
            );
        }
    }
}

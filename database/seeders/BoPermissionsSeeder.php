<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Granular permissions for the BO (Back Office / Accounting) module.
 * Naming convention: `bo.{area}.{entity}.{action}` (PRD §2.1).
 */
class BoPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('permission:cache-reset');

        $permissions = [
            // General Ledger
            'bo.gl.coa.view', 'bo.gl.coa.manage',
            'bo.gl.jurnal.view', 'bo.gl.jurnal.create', 'bo.gl.jurnal.approve',
            'bo.gl.jurnal.post', 'bo.gl.jurnal.cancel', 'bo.gl.jurnal.reverse',
            'bo.gl.periode.close',
            // Cash & Bank
            'bo.cb.voucher.view', 'bo.cb.voucher.create', 'bo.cb.voucher.post',
            'bo.cb.voucher.void', 'bo.cb.rekon.manage',
            // Accounts Receivable
            'bo.ar.invoice.view', 'bo.ar.invoice.create', 'bo.ar.invoice.post',
            'bo.ar.denda.manage', 'bo.ar.aging.view',
            // Accounts Payable
            'bo.ap.supplier.manage', 'bo.ap.invoice.view', 'bo.ap.invoice.create',
            'bo.ap.invoice.approve', 'bo.ap.invoice.post', 'bo.ap.invoice.pay', 'bo.ap.aging.view',
            // Inventory
            'bo.ivt.item.manage', 'bo.ivt.movement.view', 'bo.ivt.movement.create',
            'bo.ivt.fpb.approve', 'bo.ivt.adjust.manage',
            // Purchasing
            'bo.pr.request.view', 'bo.pr.request.create', 'bo.pr.request.approve',
            'bo.pr.po.manage',
            // HR
            'bo.hr.dept.manage', 'bo.hr.karyawan.manage',
            // FOBO
            'bo.fobo.posting.view', 'bo.fobo.posting.retry',
            // Reports
            'bo.laporan.neraca.view', 'bo.laporan.labarugi.view', 'bo.laporan.aruskas.view',
            'bo.laporan.trialbalance.view',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // super_admin gets everything; accounting/finance roles get scoped sets.
        if ($superAdmin = Role::where('name', 'super_admin')->first()) {
            $superAdmin->givePermissionTo($permissions);
        }

        $financeManager = Role::firstOrCreate(['name' => 'finance_manager', 'guard_name' => 'web']);
        $financeManager->givePermissionTo($permissions); // managers see all BO

        if ($financeStaff = Role::where('name', 'finance_staff')->first()) {
            $financeStaff->givePermissionTo(array_filter($permissions, fn ($p) => ! str_contains($p, '.approve')
                && ! str_contains($p, '.close')
                && ! str_contains($p, '.reverse')));
        }

        $accounting = Role::firstOrCreate(['name' => 'accounting', 'guard_name' => 'web']);
        $accounting->givePermissionTo($permissions);
    }
}

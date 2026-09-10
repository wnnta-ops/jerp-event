<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            // System
            'system' => [
                'system.tenant.view', 'system.tenant.manage', 'system.company.view', 'system.company.create',
                'system.company.update', 'system.company.delete', 'system.project.view', 'system.project.create',
                'system.project.update', 'system.project.delete', 'system.user.view', 'system.user.create',
                'system.user.update', 'system.user.delete', 'system.user.block', 'system.role.view',
                'system.role.create', 'system.role.update', 'system.role.delete', 'system.role.assign',
                'system.permission.view', 'system.permission.manage', 'system.audit.view', 'system.audit.export',
                'system.setup.numerator', 'system.setup.parameter', 'system.setup.notification_template', 'system.setup.approval_chain',
            ],
            // Master Customer
            'master' => [
                'master.customer.view', 'master.customer.create', 'master.customer.update', 'master.customer.delete',
                'master.customer.merge', 'master.customer.view.sensitive', 'master.customer.anonymize', 'master.customer.import',
                'master.customer.export', 'master.unit.view', 'master.unit.create', 'master.unit.update',
                'master.unit.delete', 'master.unit.update.price', 'master.unit.bulk_import', 'master.unit.siteplan.edit',
                'master.cluster.manage', 'master.block.manage', 'master.unit_type.manage', 'master.pricelist.view',
                'master.pricelist.create', 'master.pricelist.update', 'master.pricelist.activate', 'master.sales.view',
                'master.sales.create', 'master.sales.update', 'master.sales.delete', 'master.agent.view',
                'master.agent.create', 'master.agent.update', 'master.agent.delete',
            ],
            // Sales — PINDAH ke FO. Izinnya dicabut agar seksi "Sales" tidak lagi
            // muncul dobel di Grup Akses BO padahal rutenya sudah mati (lihat
            // config/modul_pindah_fo.php). Kembalikan blok ini bila sakelarnya
            // dinyalakan lagi.
            // Finance — PINDAH ke FO. Izin dicabut, lihat config/modul_pindah_fo.php.
            // Collection — PINDAH ke FO. Izin dicabut, lihat config/modul_pindah_fo.php.
            // Bank Loan
            'bankloan' => [
                'bankloan.setup.view', 'bankloan.setup.manage', 'bankloan.application.view', 'bankloan.application.create',
                'bankloan.application.update', 'bankloan.application.submit', 'bankloan.document.upload', 'bankloan.document.verify',
                'bankloan.sp3k.register', 'bankloan.akad.schedule', 'bankloan.akad.complete', 'bankloan.disbursement.create',
                'bankloan.disbursement.verify', 'bankloan.retensi.hold', 'bankloan.retensi.release', 'bankloan.buyback.request',
                'bankloan.buyback.approve',
            ],
            // Legal — rutenya dimatikan, lihat config/modul_pindah_fo.php.
            // Commission — PINDAH ke FO. Izin dicabut, lihat config/modul_pindah_fo.php.
            // Business Support (back-office akuntansi)
            'businesssupport' => [
                'businesssupport.view', 'businesssupport.setup.manage',
                'businesssupport.transaction.create', 'businesssupport.approval.act',
            ],
            // CRM Sales Pipeline — rutenya dimatikan, lihat config/modul_pindah_fo.php.
            // Security module (akses, absensi, audit)
            'security' => ['security.view', 'security.manage'],
            // Setup Sales — PINDAH ke FO. Izin dicabut, lihat config/modul_pindah_fo.php.
            // Insentif (BLT & Reward) — PINDAH ke FO. Izin dicabut.
            // Approval — PINDAH ke FO. Izin dicabut. CATATAN: yang dicabut hanya
            // modul 'approval'; 'businesssupport.approval.act' di atas TETAP ada
            // karena itu yang menjaga Approval Budget & Approval Kas/Bank di BO.
            // Reporting — PINDAH ke FO. Izin dicabut.
            ];

        foreach ($permissions as $module => $perms) {
            foreach ($perms as $name) {
                Permission::firstOrCreate(
                    ['name' => $name, 'guard_name' => 'web'],
                    [
                        'module' => $module,
                        'label'  => str($name)->afterLast('.')->headline(),
            ],
                );
            }
        }
    }
}

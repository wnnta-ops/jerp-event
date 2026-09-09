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
            // Sales
            'sales' => [
                'sales.hold.view', 'sales.hold.create', 'sales.hold.release', 'sales.booking.view',
                'sales.booking.create', 'sales.booking.update', 'sales.booking.cancel', 'sales.booking.promote',
                'sales.contract.view', 'sales.contract.update', 'sales.contract.sign', 'sales.nup.view',
                'sales.nup.create', 'sales.nup.payment', 'sales.nup.refund', 'sales.nup.verify',
                'sales.gn.request', 'sales.gu.request', 'sales.reschedule.request',
            ],
            // Finance
            'finance' => [
                'finance.tts.view', 'finance.tts.create', 'finance.tts.post', 'finance.tts.giro.manage',
                'finance.tts.giro.reject', 'finance.va.view', 'finance.va.assign', 'finance.va.transactions.view',
                'finance.memo.view', 'finance.memo.create', 'finance.kuitansi.view', 'finance.cashback.view',
                'finance.cashback.request', 'finance.cashback.approve', 'finance.cashback.pay', 'finance.fakturpajak.view',
                'finance.fakturpajak.create', 'finance.refund.request', 'finance.refund.approve', 'finance.refund.pay',
                'finance.anonim.view', 'finance.anonim.allocate',
            ],
            // Collection
            'collection' => [
                'collection.followup.view', 'collection.followup.create', 'collection.denda.view', 'collection.denda.waive',
                'collection.pjt.view', 'collection.pjt.regenerate', 'collection.tunggakan.view', 'collection.sp.view',
                'collection.sp.issue',
            ],
            // Bank Loan
            'bankloan' => [
                'bankloan.setup.view', 'bankloan.setup.manage', 'bankloan.application.view', 'bankloan.application.create',
                'bankloan.application.update', 'bankloan.application.submit', 'bankloan.document.upload', 'bankloan.document.verify',
                'bankloan.sp3k.register', 'bankloan.akad.schedule', 'bankloan.akad.complete', 'bankloan.disbursement.create',
                'bankloan.disbursement.verify', 'bankloan.retensi.hold', 'bankloan.retensi.release', 'bankloan.buyback.request',
                'bankloan.buyback.approve',
            ],
            // Legal
            'legal' => [
                'legal.ppjb.view', 'legal.ppjb.issue', 'legal.ajb.view', 'legal.ajb.issue',
                'legal.bast.view', 'legal.bast.issue', 'legal.imb.view', 'legal.imb.manage',
                'legal.sertifikat.view', 'legal.sertifikat.manage',
            ],
            // Commission
            'commission' => [
                'commission.view', 'commission.payout.request', 'commission.payout.approve', 'commission.payout.execute',
            ],
            // Business Support (back-office akuntansi)
            'businesssupport' => [
                'businesssupport.view', 'businesssupport.setup.manage',
                'businesssupport.transaction.create', 'businesssupport.approval.act',
            ],
            // CRM Sales Pipeline (lead management)
            'crm' => ['crm.view', 'crm.manage'],
            // Security module (akses, absensi, audit)
            'security' => ['security.view', 'security.manage'],
            // Setup Sales (skema bayar, master sales/agen, price list, dll)
            'setupsales' => ['setupsales.view', 'setupsales.manage'],
            // Insentif (BLT & Reward) — pengajuan, persetujuan, realisasi
            'incentive' => ['incentive.view', 'incentive.manage', 'incentive.approve'],
            // Approval
            'approval' => ['approval.view', 'approval.act',
            ],
            // Reporting
            'reporting' => [
                'reporting.dashboard.executive',
                'reporting.report.export',
            ],
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

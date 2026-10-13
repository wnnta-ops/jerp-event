<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Katalog permission GRANULAR untuk modul back-office (AP, AR, GL, Cash Book, Budget,
 * Contractor, COGS, Tax, Data Center, Business Support umum) — dipisah agar modul besar
 * bisa diatur hak aksesnya per sub-modul/aksi di Settings -> Roles. Idempoten (updateOrCreate).
 */
class GranularPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'ap' => [
                'ap.aging.export', 'ap.aging.view', 'ap.berkas.create', 'ap.berkas.delete',
                'ap.berkas.manage', 'ap.berkas.toggle', 'ap.berkas.update', 'ap.berkas.view',
                'ap.category.create', 'ap.category.delete', 'ap.category.manage', 'ap.category.update',
                'ap.category.view', 'ap.control.view', 'ap.costcenter.override',
                'ap.invoice.create', 'ap.invoice.pay',
                'ap.invoice.print', 'ap.invoice.show', 'ap.invoice.view', 'ap.kuitansi.create',
                'ap.reimburse.view', 'ap.reimburse.create', 'ap.deposit.view', 'ap.deposit.create',
                'ap.kuitansi.show', 'ap.kuitansi.store', 'ap.kuitansi.view', 'ap.kuitansi.void',
                'ap.memo', 'ap.memorial.create', 'ap.memorial.store', 'ap.memorial.view',
                'ap.opening.create', 'ap.opening.delete', 'ap.opening.view', 'ap.paycard.view',
                'ap.penghapusan.store', 'ap.penghapusan.view', 'ap.posting.create', 'ap.posting.view',
                'ap.print', 'ap.printhub', 'ap.repair.manage', 'ap.repair.view',
            ],
            'ar' => [
                'ar.aging.view', 'ar.booked-revenue.cancel', 'ar.booked-revenue.create', 'ar.booked-revenue.recognize',
                'ar.booked-revenue.view', 'ar.card.view', 'ar.category.manage', 'ar.create',
                'ar.kuitansi.create', 'ar.kuitansi.view', 'ar.kuitansi.void', 'ar.memorial.create',
                'ar.memorial.view', 'ar.pay', 'ar.penghapusan.create', 'ar.penghapusan.view',
                'ar.piutang-lain.view',
                'ar.reschedule', 'ar.update', 'ar.view',
            ],
            'gl' => [
                'gl.account.create', 'gl.account.delete', 'gl.account.manage', 'gl.account.update',
                'gl.account.view', 'gl.bankrec.finalize', 'gl.bankrec.toggle', 'gl.bankrec.view',
                'gl.cashflow.direct.view', 'gl.cashflow.indirect.view', 'gl.cf-category.create', 'gl.cf-category.delete',
                'gl.cf-category.manage', 'gl.cf-category.update', 'gl.cf-category.view', 'gl.cf-mapping.create',
                'gl.cf-mapping.delete', 'gl.cf-mapping.manage', 'gl.cf-mapping.update', 'gl.cf-mapping.view',
                'gl.closing.close', 'gl.closing.reopen', 'gl.closing.view', 'gl.closing.year',
                'gl.comparative.view', 'gl.cost-center.create', 'gl.cost-center.delete', 'gl.cost-center.manage',
                'gl.cost-center.update', 'gl.cost-center.view', 'gl.currency.create', 'gl.currency.delete',
                'gl.currency.manage', 'gl.currency.update', 'gl.currency.view', 'gl.extra-report.view',
                'gl.financials.view', 'gl.journal-dimension.create', 'gl.journal-dimension.delete', 'gl.journal-dimension.manage',
                'gl.journal-dimension.update', 'gl.journal-dimension.view', 'gl.journal.approve', 'gl.journal.create',
                'gl.journal.edit', 'gl.journal.export', 'gl.journal.finalize', 'gl.journal.import',
                'gl.journal.reject', 'gl.journal.update', 'gl.journal.view', 'gl.kurs.create',
                'gl.kurs.delete', 'gl.kurs.manage', 'gl.kurs.update', 'gl.kurs.view',
                'gl.late-closing.close', 'gl.late-closing.view', 'gl.ledger.view', 'gl.period.close',
                'gl.period.open', 'gl.printhub.view', 'gl.repair.run', 'gl.repair.view',
                'gl.saldo-awal.confirm', 'gl.saldo-awal.create', 'gl.saldo-awal.view', 'gl.transaction-rule.create',
                'gl.transaction-rule.delete', 'gl.transaction-rule.manage', 'gl.transaction-rule.update', 'gl.transaction-rule.view',
                'gl.trialbalance.view', 'gl.view', 'gl.worksheet.view',
            ],
            'cashbook' => [
                'cashbook.advance.approve', 'cashbook.advance.card', 'cashbook.advance.create', 'cashbook.advance.pay',
                'cashbook.advance.print', 'cashbook.advance.print-realisasi', 'cashbook.advance.realize', 'cashbook.advance.received-date',
                'cashbook.advance.refund', 'cashbook.advance.reimburse', 'cashbook.advance.report', 'cashbook.advance.report.excel',
                'cashbook.advance.report.pdf', 'cashbook.advance.view', 'cashbook.approval-retensi.post', 'cashbook.approval-retensi.view',
                'cashbook.approval-utjdp.post', 'cashbook.approval-utjdp.reject', 'cashbook.approval-utjdp.view', 'cashbook.bankrec.finalize',
                'cashbook.bankrec.toggle', 'cashbook.bankrec.view', 'cashbook.cheque-out.clear', 'cashbook.cheque-out.create',
                'cashbook.cheque-out.handover', 'cashbook.cheque-out.import', 'cashbook.cheque-out.print', 'cashbook.cheque-out.view',
                'cashbook.cheque-out.void', 'cashbook.giro-calendar.view', 'cashbook.giro.bounce', 'cashbook.giro.clear',
                'cashbook.giro.create', 'cashbook.giro.view', 'cashbook.hub.view', 'cashbook.ledger.view',
                'cashbook.opening.create', 'cashbook.opening.delete', 'cashbook.opening.view', 'cashbook.pettycash.create',
                'cashbook.pettycash.view', 'cashbook.print.view', 'cashbook.repair.run', 'cashbook.repair.view',
                'cashbook.saldo.view', 'cashbook.transaction.approve', 'cashbook.transaction.create', 'cashbook.transaction.export',
                'cashbook.transaction.print', 'cashbook.transaction.reject', 'cashbook.transaction.update', 'cashbook.transaction.view',
            ],
            'budget' => [
                'budget.allocation.approve', 'budget.allocation.create', 'budget.allocation.view', 'budget.correction.approve',
                'budget.correction.create', 'budget.correction.view', 'budget.master.approve', 'budget.master.create',
                'budget.master.delete', 'budget.master.export', 'budget.master.mutate', 'budget.master.submit',
                'budget.master.update', 'budget.master.view', 'budget.query.allocation', 'budget.query.correction',
                'budget.query.master', 'budget.query.realization', 'budget.report.print', 'budget.upload.approve',
                'budget.upload.view',
            ],
            'contractor' => [
                'contractor.general.print', 'contractor.general.view', 'contractor.kpl.create', 'contractor.kpl.update',
                'contractor.kpl.view', 'contractor.material.close', 'contractor.material.create', 'contractor.material.return',
                'contractor.material.view', 'contractor.mutation.approve', 'contractor.mutation.create', 'contractor.mutation.view',
                'contractor.project.create', 'contractor.project.delete', 'contractor.project.update', 'contractor.project.view',
                'contractor.query.view', 'contractor.retention.create', 'contractor.retention.release', 'contractor.retention.view',
                'contractor.spk.addendum', 'contractor.spk.adjust-ppn', 'contractor.spk.cancel', 'contractor.spk.create',
                'contractor.spk.material.use', 'contractor.spk.mutate-progress', 'contractor.spk.progress', 'contractor.spk.progress.pay',
                'contractor.spk.retensi', 'contractor.spk.update', 'contractor.spk.view',
            ],
            // COGS LAMA DITUTUP 12 Okt 2026 — izin `cogs.*` dicabut migrasi
            // 2026_10_12_100000. Blok katalognya dihapus dari sini, bukan sekadar
            // dikosongkan di database: seeder ini idempoten dan dijalankan tiap deploy,
            // jadi selama namanya masih tertulis di sini satu `db:seed` akan
            // menerbitkannya kembali — izin yang hidup lagi tanpa satu pun menu.
            //
            // COGS Baru memakai izinnya sendiri, `cogs2.*`, yang dibuat migrasi itu.
            'cogs2' => [
                'cogs2.view', 'cogs2.manage',
            ],
            'tax' => [
                'tax.efaktur', 'tax.export', 'tax.nsfp', 'tax.pph.export',
                'tax.pph.view', 'tax.ppn-keluaran.view', 'tax.ppn-masukan.view', 'tax.print',
                'tax.rekap', 'tax.view',
            ],
            'datacenter' => [
                'datacenter.department.create', 'datacenter.department.delete', 'datacenter.department.update', 'datacenter.department.view',
                'datacenter.employee.create', 'datacenter.employee.merge', 'datacenter.employee.print', 'datacenter.employee.registrasi',
                'datacenter.employee.repair', 'datacenter.employee.view', 'datacenter.hub.view', 'datacenter.supplier.category.create',
                'datacenter.supplier.category.delete', 'datacenter.supplier.category.update', 'datacenter.supplier.category.view', 'datacenter.supplier.export',
                'datacenter.supplier.merge', 'datacenter.supplier.print', 'datacenter.supplier.repair', 'datacenter.supplier.report',
                'datacenter.supplier.show', 'datacenter.supplier.upload', 'datacenter.supplier.view',
            ],
            'bs' => [
                'bs.asset.create', 'bs.asset.depreciate', 'bs.asset.dispose', 'bs.asset.opening',
                'bs.asset.run', 'bs.asset.sell', 'bs.asset.view', 'bs.bulk-import.manage',
                'bs.fpb.approve', 'bs.fpb.create', 'bs.fpb.view', 'bs.inventory-setup.manage',
                'bs.issue.create', 'bs.issue.view', 'bs.item.manage', 'bs.item.view',
                'bs.overview.view', 'bs.payroll.create', 'bs.payroll.view', 'bs.po.adjust-ppn',
                'bs.po.approve', 'bs.po.close', 'bs.po.create', 'bs.po.revise',
                'bs.po.termin', 'bs.po.view', 'bs.projection.view', 'bs.receipt.create',
                'bs.receipt.view', 'bs.sip.approve', 'bs.sip.cancel', 'bs.sip.create',
                'bs.sip.print', 'bs.sip.reject', 'bs.sip.update', 'bs.sip.view',
                'bs.stockcard.view',
            ],
        ];

        /*
         * IZIN TAMPIL MENU — katalognya di config/menu_permissions.php, diturunkan dari
         * navGroups.ts supaya tidak pernah berbeda dari sidebar. Ditambahkan di sini, bukan
         * ditulis ulang sebagai daftar kedua: dua daftar yang suatu saat berbeda berarti menu
         * yang punya sakelar di satu tempat dan tidak di tempat lain.
         *
         * Hanya MEMBUAT izinnya. Pemberiannya ke peran dilakukan migrasi 2026_10_12_200000,
         * yang mewarisinya dari izin aksi supaya tidak ada sidebar yang mendadak kosong.
         *
         * Dibaca langsung dari berkasnya: config() tidak melihatnya selama cache config lama
         * masih ada (lihat migrasi di atas).
         */
        $katalogMenu = require base_path('config/menu_permissions.php');

        foreach (array_keys($katalogMenu) as $nama) {
            $permissions['menu'][] = $nama;
        }

        foreach ($permissions as $module => $perms) {
            foreach ($perms as $name) {
                // Izin menu.* dilabeli JALUR MENUNYA ("Tampil: Master › Invoice Hutang").
                // Label turunan-nama akan berbunyi "Bs Ap Index" — nama rute, bukan nama menu —
                // dan seeder ini updateOrCreate, jadi sekali dijalankan ia akan menimpa label
                // bagus yang dipasang migrasi 2026_10_12_200000 dengan yang tak terbaca itu.
                $label = isset($katalogMenu[$name])
                    ? 'Tampil: '.$katalogMenu[$name][1]
                    : (string) Str::of($name)->after('.')->replace(['.', '-', '_'], ' ')->headline();

                Permission::updateOrCreate(
                    ['name' => $name, 'guard_name' => 'web'],
                    ['module' => $module, 'label' => $label],
                );
            }
        }

        $this->lengkapiSuperAdmin();
    }

    /**
     * Izin yang baru dibuat langsung diberikan ke peran SuperAdmin.
     *
     * Tanpa ini seeder hanya MEMBUAT izin dan tidak memberikannya kepada siapa pun. Yang selama
     * ini memberi SuperAdmin seluruh izin adalah RoleSeeder, dan RoleSeeder tidak ikut dijalankan
     * setiap kali sebuah fitur menambah izin. Akibatnya izin baru menjadi yatim: menunya
     * tersembunyi dari SEMUA orang — sidebar menyaring lewat daftar nama izin, yang tidak
     * terpengaruh bypass Gate::before milik SuperAdmin — sementara halamannya sendiri tetap bisa
     * dibuka dengan mengetik alamatnya. Kegagalan itu tidak memunculkan galat apa pun.
     *
     * HANYA MENAMBAH, dan HANYA pada SuperAdmin. Peran lain tidak disentuh: set izinnya adalah
     * keputusan operator di Settings → Roles, dan seeder yang ikut mengaturnya akan menimpa
     * penyesuaian yang sudah berjalan.
     */
    private function lengkapiSuperAdmin(): void
    {
        foreach (Role::where('name', 'SuperAdmin')->get() as $role) {
            $kurang = Permission::whereNotIn('id', $role->permissions()->pluck('permissions.id'))->get();

            if ($kurang->isNotEmpty()) {
                // givePermissionTo() membersihkan cache izin Spatie sendiri.
                $role->givePermissionTo($kurang);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Approval\Models\ApprovalChain;
use App\Domain\Approval\Models\ApprovalChainStep;
use App\Domain\ApprovalInbox\Models\MemoInternal;
use App\Domain\BusinessSupport\Models\BsApBerkasUpload;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Services\ApBerkasService;
use App\Domain\Foundation\Tenant\Models\Tenant;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Upload Berkas (Account Payable › Master) & approval invoice hutang dari berkas.
 *
 *   ap_berkas  BRK-DEFAULT : 1 langkah — Dept Head yang DIPILIH pengunggah ('assigned')
 *   ap_invoice INV-DEFAULT : Accounting → Finance → GM → Direktur → MW / BA (= Memo Internal)
 *
 * Semua peran internal boleh mengunggah berkas (ap.berkas.upload). Invoice Hutang dibuat oleh
 * PENGUNGGAH berkasnya sendiri (wajib pilih berkas miliknya di Detil Tagihan) — peran di
 * PENGAJU_INVOICE ikut diberi ap.invoice.create. Aman dijalankan ulang (idempoten).
 */
class ApBerkasApprovalSeeder extends Seeder
{
    /** Peran staf pengunggah yang juga meregistrasi Invoice Hutang dari berkasnya sendiri. */
    public const PENGAJU_INVOICE = ['Collection'];

    public function run(): void
    {
        $tenant = Tenant::where('code', 'MUP')->firstOrFail();

        Role::firstOrCreate(
            ['name' => ApBerkasService::DEPT_HEAD_ROLE, 'tenant_id' => $tenant->id, 'guard_name' => 'web'],
            ['description' => 'Dept Head — penanda tangan pertama Upload Berkas', 'is_system' => true],
        );

        Permission::firstOrCreate(
            ['name' => 'ap.berkas.upload', 'guard_name' => 'web'],
            ['module' => 'ap', 'label' => 'Upload Berkas'],
        );
        Role::query()->with('permissions')->whereNotIn('name', ['Agent', 'Viewer'])->get()
            ->each(fn (Role $r) => $r->givePermissionTo('ap.berkas.upload'));

        // Hak per aksi (v2): lihat semua, kelola (batal/tutup/hapus milik siapa pun, lihat yang
        // dihapus), export. Diberikan ke peran tim AP. Kelola diturunkan dari hak BAYAR, bukan
        // ap.invoice.create — hak buat invoice kini juga dipegang staf pengunggah (PENGAJU_INVOICE),
        // yang tidak boleh mengelola berkas orang lain.
        $izin = [
            'ap.berkas.view' => ['Lihat Semua Berkas', 'ap.invoice.view'],
            'ap.berkas.export' => ['Export Berkas', 'ap.invoice.view'],
            'ap.berkas.manage' => ['Kelola Berkas', 'ap.invoice.pay'],
        ];
        foreach ($izin as $name => [$label, $dariIzin]) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['module' => 'ap', 'label' => $label]);
            Role::query()->with('permissions')->get()
                ->filter(fn (Role $r) => $r->name === 'SuperAdmin' || $r->hasPermissionTo($dariIzin))
                ->each(fn (Role $r) => $r->givePermissionTo($name));
        }

        // Staf pengunggah meregistrasi Invoice Hutang dari berkasnya sendiri. Cetak memo sesudah
        // simpan diizinkan untuk pembuat invoicenya (ApInvoiceController::memo), tanpa ap.memo.
        // menu.bs.ap.create = izin TAMPIL butir sidebar Registrasi Invoice Hutang (lihat
        // migrasi izin_tampil_menu) — tanpa itu layarnya bisa dibuka tetapi menunya tidak ada.
        Permission::firstOrCreate(['name' => 'menu.bs.ap.create', 'guard_name' => 'web'],
            ['module' => 'menu', 'label' => 'Tampil: Generate Invoice › Registrasi Invoice Hutang']);
        Role::query()->whereIn('name', self::PENGAJU_INVOICE)->get()
            ->each(fn (Role $r) => $r->givePermissionTo(['ap.invoice.create', 'menu.bs.ap.create']));

        $berkas = ApprovalChain::updateOrCreate(
            ['tenant_id' => $tenant->id, 'project_id' => null, 'document_type' => BsApBerkasUpload::DOCUMENT_TYPE, 'code' => 'BRK-DEFAULT'],
            ['name' => 'TTD Dept Head — Upload Berkas', 'match_conditions' => null, 'is_active' => true],
        );
        ApprovalChainStep::updateOrCreate(
            ['approval_chain_id' => $berkas->id, 'level' => 1],
            ['approver_type' => 'assigned', 'approver_value' => ApBerkasService::DEPT_HEAD_ROLE, 'is_final' => true],
        );

        $invoice = ApprovalChain::updateOrCreate(
            ['tenant_id' => $tenant->id, 'project_id' => null, 'document_type' => BsApInvoice::APPROVAL_DOCUMENT_TYPE, 'code' => 'INV-DEFAULT'],
            ['name' => 'Invoice Hutang dari Upload Berkas', 'match_conditions' => null, 'is_active' => true],
        );
        foreach (MemoInternal::STEPS as $level => [$role]) {
            ApprovalChainStep::updateOrCreate(
                ['approval_chain_id' => $invoice->id, 'level' => $level],
                ['approver_type' => 'role', 'approver_value' => $role, 'is_final' => $level === array_key_last(MemoInternal::STEPS)],
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

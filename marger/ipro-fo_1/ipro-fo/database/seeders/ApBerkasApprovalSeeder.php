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
 * Semua peran internal boleh mengunggah berkas (ap.berkas.upload); yang membuat
 * invoicenya tetap pemegang ap.invoice.create. Aman dijalankan ulang (idempoten).
 */
class ApBerkasApprovalSeeder extends Seeder
{
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
        // dihapus), export. Diberikan ke peran yang sudah memegang hak invoice hutang.
        $izin = [
            'ap.berkas.view' => ['Lihat Semua Berkas', 'ap.invoice.view'],
            'ap.berkas.export' => ['Export Berkas', 'ap.invoice.view'],
            'ap.berkas.manage' => ['Kelola Berkas', 'ap.invoice.create'],
        ];
        foreach ($izin as $name => [$label, $dariIzin]) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['module' => 'ap', 'label' => $label]);
            Role::query()->with('permissions')->get()
                ->filter(fn (Role $r) => $r->name === 'SuperAdmin' || $r->hasPermissionTo($dariIzin))
                ->each(fn (Role $r) => $r->givePermissionTo($name));
        }

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

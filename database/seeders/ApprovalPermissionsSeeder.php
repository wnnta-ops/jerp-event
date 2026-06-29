<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Default izin approval (.approve) per role, agar approval bisa dipakai role selain
 * super_admin. Idempotent. Sesuaikan via Grup Akses bila kebijakan berbeda.
 *
 *  - direktur       : final approver (L2/GM) untuk SEMUA jenis (lihat config approval.final_roles).
 *  - sales_manager  : approver jenis terkait penjualan.
 *  - finance_manager: approver jenis terkait keuangan.
 *  - super_admin    : sudah lewat ApprovalPolicy::before (tetap diberi izin agar konsisten).
 */
class ApprovalPermissionsSeeder extends Seeder
{
    /** @var array<string,string[]> permission .approve => role yang menerima */
    private array $sales = [
        'fo.approval_diskon.approve',
        'fo.approval_pembatalan.approve',
        'fo.approval_adjustment.approve',
        'fo.approval_reschedule.approve',
        'fo.approval_pengalihan_hak.approve',
        'fo.approval_pindah_unit.approve',
        'fo.approval_customize.approve',
    ];

    private array $finance = [
        'fo.refund.approve',
        'fo.pengajuan_blt.approve',
        'fo.pengajuan_komisi.approve',
    ];

    public function run(): void
    {
        $all = array_merge($this->sales, $this->finance);

        foreach ($all as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // direktur + super_admin: final approver semua jenis.
        foreach (['direktur', 'super_admin'] as $roleName) {
            if ($role = Role::where('name', $roleName)->where('guard_name', 'web')->first()) {
                $role->givePermissionTo($all);
            }
        }

        if ($salesMgr = Role::where('name', 'sales_manager')->where('guard_name', 'web')->first()) {
            $salesMgr->givePermissionTo($this->sales);
        }

        if ($finMgr = Role::where('name', 'finance_manager')->where('guard_name', 'web')->first()) {
            $finMgr->givePermissionTo($this->finance);
        }

        app()['cache']->forget('spatie.permission.cache');
    }
}

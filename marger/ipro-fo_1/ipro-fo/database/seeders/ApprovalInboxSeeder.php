<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Approval\Models\ApprovalChain;
use App\Domain\Approval\Models\ApprovalChainStep;
use App\Domain\ApprovalInbox\Models\MemoInternal;
use App\Domain\Foundation\Numerator\Models\Numerator;
use App\Domain\Foundation\Tenant\Models\Tenant;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Approval Inbox — rantai Memo Internal 6 tahap:
 *   1 Dept Head (pengaju — bukan langkah approval, yaitu yang mengajukan)
 *   2 Accounting (alokasi budget / non budget) · 3 Finance (verifikasi)
 *   4 GM · 5 Direktur · 6 MW / BA (role Owner — salah satu cukup)
 *
 * Approver tiap tahap = ROLE, jadi siapa yang memegangnya diatur lewat Grup Akses
 * tanpa ubah kode. Aman dijalankan ulang (idempoten).
 */
class ApprovalInboxSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('code', 'MUP')->firstOrFail();

        $roles = [
            'Accounting' => 'Accounting — alokasi budget / non budget memo',
            'GM' => 'General Manager — approval memo',
            'Owner' => 'Owner (MW / BA) — approval akhir memo',
        ];
        foreach ($roles as $name => $description) {
            Role::firstOrCreate(
                ['name' => $name, 'tenant_id' => $tenant->id, 'guard_name' => 'web'],
                ['description' => $description, 'is_system' => true],
            );
        }

        foreach (['approvalinbox.view', 'approvalinbox.submit'] as $name) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['module' => 'approvalinbox', 'label' => str($name)->afterLast('.')->headline()],
            );
        }

        // Semua peran internal boleh membuka inbox & mengajukan memo; yang terlihat
        // dibatasi per baris (milik sendiri / giliran saya), bukan per menu.
        Role::query()->with('permissions')->whereNotIn('name', ['Agent', 'Viewer'])->get()
            ->each(fn (Role $r) => $r->givePermissionTo(['approvalinbox.view', 'approvalinbox.submit']));

        $chain = ApprovalChain::updateOrCreate(
            ['tenant_id' => $tenant->id, 'project_id' => null, 'document_type' => MemoInternal::DOCUMENT_TYPE, 'code' => 'MI-DEFAULT'],
            ['name' => 'Memo Internal', 'match_conditions' => null, 'is_active' => true],
        );
        foreach (MemoInternal::STEPS as $level => [$role]) {
            ApprovalChainStep::updateOrCreate(
                ['approval_chain_id' => $chain->id, 'level' => $level],
                ['approver_type' => 'role', 'approver_value' => $role, 'is_final' => $level === array_key_last(MemoInternal::STEPS)],
            );
        }

        Numerator::updateOrCreate(
            ['tenant_id' => $tenant->id, 'project_id' => null, 'code' => 'MI'],
            ['prefix' => 'MI', 'format' => '{PREFIX}/{YY}{MM}/{SEQ}', 'seq_padding' => 5, 'reset_strategy' => 'monthly'],
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

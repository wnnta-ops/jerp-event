<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Approval Chain yang diatur dari System › Approval Chain (approver per level = user tertentu).
 *
 *   approval_chain_steps.label          : nama tahap di tracker (mis. "Accounting", "MW / BA").
 *   approval_steps.approver_type/value/label : SNAPSHOT susunan chain saat dokumen diajukan —
 *       mengubah chain hanya berlaku untuk pengajuan berikutnya; yang sudah berjalan tetap.
 *   memo_internals.jenis                : urgent | reguler (memo lama = urgent).
 *   system.setup.approval_chain         : izin layar Approval Chain.
 */
return new class extends Migration
{
    /** Label tahap Memo Internal / Invoice Hutang sebelum chain bisa diatur (MemoInternal::STEPS lama). */
    private const LABEL_LAMA = [1 => 'Accounting', 2 => 'Finance', 3 => 'GM', 4 => 'Direktur', 5 => 'MW / BA'];

    public function up(): void
    {
        Schema::table('approval_chain_steps', function (Blueprint $t): void {
            $t->string('label', 100)->nullable()->after('approver_value');
        });
        Schema::table('approval_steps', function (Blueprint $t): void {
            $t->string('approver_type', 32)->nullable()->after('approver_id');
            $t->string('approver_value')->nullable()->after('approver_type');
            $t->string('label', 100)->nullable()->after('approver_value');
        });
        Schema::table('memo_internals', function (Blueprint $t): void {
            $t->string('jenis', 16)->default('urgent')->after('memo_date');
        });

        // Label chain yang sudah ada.
        $memoChains = DB::table('approval_chains')->whereIn('document_type', ['memo_internal', 'ap_invoice'])->pluck('id');
        foreach (self::LABEL_LAMA as $level => $label) {
            DB::table('approval_chain_steps')->whereIn('approval_chain_id', $memoChains)->where('level', $level)
                ->whereNull('label')->update(['label' => $label]);
        }
        DB::table('approval_chain_steps')->whereNull('label')->where('approver_type', 'assigned')->update(['label' => 'Dept. Head (TTD)']);
        DB::table('approval_chain_steps')->whereNull('label')->update(['label' => DB::raw('approver_value')]);

        // Snapshot untuk approval yang sudah berjalan = susunan chain-nya saat ini.
        DB::table('approval_steps as s')
            ->join('approvals as a', 'a.id', '=', 's.approval_id')
            ->join('approval_chain_steps as c', function ($j): void {
                $j->on('c.approval_chain_id', '=', 'a.approval_chain_id')->on('c.level', '=', 's.level');
            })
            ->whereNull('s.approver_type')
            ->update([
                's.approver_type' => DB::raw('c.approver_type'),
                's.approver_value' => DB::raw('c.approver_value'),
                's.label' => DB::raw('c.label'),
            ]);

        $ada = DB::table('permissions')->where('name', 'system.setup.approval_chain')->where('guard_name', 'web');
        if (! $ada->exists()) {
            DB::table('permissions')->insert([
                'name' => 'system.setup.approval_chain', 'guard_name' => 'web', 'module' => 'system',
                'label' => 'Setup — Approval Chain', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', 'system.setup.approval_chain')->value('id');
        if ($id) {
            DB::table('role_has_permissions')->where('permission_id', $id)->delete();
            DB::table('model_has_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
        Schema::table('memo_internals', fn (Blueprint $t) => $t->dropColumn('jenis'));
        Schema::table('approval_steps', fn (Blueprint $t) => $t->dropColumn(['approver_type', 'approver_value', 'label']));
        Schema::table('approval_chain_steps', fn (Blueprint $t) => $t->dropColumn('label'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

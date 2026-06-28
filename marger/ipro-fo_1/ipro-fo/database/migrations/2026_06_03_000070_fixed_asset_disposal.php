<?php

declare(strict_types=1);

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\Foundation\Tenant\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 1 — Aset: Penghapusan (write-off), Penjualan (sale), Opening Saldo Penyusutan.
 *   (1) Kolom pelepasan di bs_fixed_assets.
 *   (2) Akun Laba Penjualan Aset Tetap (income) & Rugi Penjualan/Penghapusan Aset Tetap (expense).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_fixed_assets', function (Blueprint $t): void {
            if (! Schema::hasColumn('bs_fixed_assets', 'disposed_date')) {
                $t->date('disposed_date')->nullable();
            }
            if (! Schema::hasColumn('bs_fixed_assets', 'disposal_type')) {
                $t->string('disposal_type', 20)->nullable(); // write_off | sale
            }
            if (! Schema::hasColumn('bs_fixed_assets', 'disposal_proceeds')) {
                $t->decimal('disposal_proceeds', 18, 2)->default(0);
            }
            if (! Schema::hasColumn('bs_fixed_assets', 'disposal_note')) {
                $t->string('disposal_note', 255)->nullable();
            }
            if (! Schema::hasColumn('bs_fixed_assets', 'disposal_journal_id')) {
                $t->unsignedBigInteger('disposal_journal_id')->nullable();
            }
        });

        // Akun laba/rugi pelepasan aset — idempoten, per tenant.
        foreach (Tenant::all() as $tenant) {
            $this->ensureAccount($tenant->id, [
                'code' => '40201010102', 'name' => 'LABA PENJUALAN ASET TETAP',
                'category_code' => '4', 'type' => 'income', 'level' => 6,
                'parent_code' => '402010101', 'normal_balance' => 'C',
            ]);
            $this->ensureAccount($tenant->id, [
                'code' => '50302020230', 'name' => 'RUGI PENJUALAN/PENGHAPUSAN ASET TETAP',
                'category_code' => '5', 'type' => 'expense', 'level' => 6,
                'parent_code' => '503020202', 'normal_balance' => 'D',
            ]);
        }
    }

    private function ensureAccount(int $tenantId, array $data): void
    {
        if (BsAccount::where('tenant_id', $tenantId)->where('code', $data['code'])->exists()) {
            return;
        }
        $parentId = BsAccount::where('tenant_id', $tenantId)->where('code', $data['parent_code'])->value('id');
        if (! $parentId) {
            return; // struktur CoA tenant berbeda — lewati daripada merusak.
        }
        BsAccount::create(array_merge($data, [
            'tenant_id' => $tenantId, 'parent_id' => $parentId,
            'is_postable' => true, 'is_active' => true,
        ]));
    }

    public function down(): void
    {
        Schema::table('bs_fixed_assets', function (Blueprint $t): void {
            foreach (['disposed_date', 'disposal_type', 'disposal_proceeds', 'disposal_note', 'disposal_journal_id'] as $c) {
                if (Schema::hasColumn('bs_fixed_assets', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};

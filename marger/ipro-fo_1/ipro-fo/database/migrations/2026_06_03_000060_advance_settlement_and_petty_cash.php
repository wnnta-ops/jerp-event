<?php

declare(strict_types=1);

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\Foundation\Tenant\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (1) Bon Sementara: kolom settlement deferred (Refund/Reimburse terpisah).
 * (2) Kas Kecil (Petty Cash): akun "KAS KECIL" di bawah Kas & Setara Kas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_cash_advances', function (Blueprint $t): void {
            if (! Schema::hasColumn('bs_cash_advances', 'settlement_status')) {
                $t->string('settlement_status', 20)->nullable(); // null|pending|settled
            }
            if (! Schema::hasColumn('bs_cash_advances', 'settle_journal_id')) {
                $t->unsignedBigInteger('settle_journal_id')->nullable();
            }
        });

        // Akun Kas Kecil (petty cash) — idempoten.
        $tenant = Tenant::whereRaw('LOWER(code)=?', ['mup'])->first() ?? Tenant::orderBy('id')->first();
        if ($tenant && ! BsAccount::where('tenant_id', $tenant->id)->where('code', '10101010102')->exists()) {
            $parentId = BsAccount::where('tenant_id', $tenant->id)->where('code', '101010101')->value('id');
            BsAccount::create([
                'tenant_id' => $tenant->id, 'code' => '10101010102', 'name' => 'KAS KECIL',
                'category_code' => '1', 'type' => 'asset', 'level' => 6, 'parent_code' => '101010101',
                'parent_id' => $parentId, 'normal_balance' => 'D', 'is_postable' => true, 'is_active' => true,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('bs_cash_advances', function (Blueprint $t): void {
            foreach (['settlement_status', 'settle_journal_id'] as $c) {
                if (Schema::hasColumn('bs_cash_advances', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};

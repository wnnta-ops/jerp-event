<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Satu COA boleh dipetakan ke BEBERAPA cost center (mis. Promosi → Marcom, Marketing).
 *
 * Tabel ini = daftar cost center yang BOLEH dipakai akun itu. bs_accounts.cost_center_id tetap
 * ada dan kini berarti DEFAULT — dipakai bila unit kerja penginput tidak ada di daftar (lihat
 * CostCenterAkun). Akun yang sudah punya cost center tunggal ikut didaftarkan di sini, jadi
 * perilakunya tidak berubah: daftar berisi satu = selalu cost center itu.
 *
 * Forward-only: baris jurnal lama tidak disentuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bs_account_cost_centers')) {
            Schema::create('bs_account_cost_centers', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('account_id')->constrained('bs_accounts')->cascadeOnDelete();
                $t->foreignId('cost_center_id')->constrained('bs_cost_centers')->cascadeOnDelete();
                $t->timestamps();
                $t->unique(['account_id', 'cost_center_id']);
            });
        }

        $now = now();
        DB::table('bs_accounts')->whereNotNull('cost_center_id')->whereNull('deleted_at')
            ->whereIn('cost_center_id', DB::table('bs_cost_centers')->select('id'))
            ->orderBy('id')->get(['id', 'cost_center_id'])
            ->each(fn ($a) => DB::table('bs_account_cost_centers')->insertOrIgnore([
                'account_id' => $a->id, 'cost_center_id' => $a->cost_center_id, 'created_at' => $now, 'updated_at' => $now,
            ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_account_cost_centers');
    }
};

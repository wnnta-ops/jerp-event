<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Akun pakem Bayar Memo Urgent: 10107030304 AKUN MEMO URGENT (aset, postable) di bawah
 * 101070303 UANG MUKA LAINNYA — sejajar Uang Muka Operasional. Kuitansi Memo Urgent selalu
 * mendebit akun ini (PembayaranMemoUrgent::KODE_AKUN_MEMO); dipotong saat invoice hutangnya dibayar.
 *
 * Hanya untuk tenant yang sudah punya induknya (COA terimpor); DB baru tanpa COA dilewati.
 */
return new class extends Migration
{
    private const KODE = '10107030304';

    private const INDUK = '101070303';

    public function up(): void
    {
        foreach (DB::table('bs_accounts')->where('code', self::INDUK)->whereNull('deleted_at')->get() as $induk) {
            if (DB::table('bs_accounts')->where('tenant_id', $induk->tenant_id)->where('code', self::KODE)->exists()) {
                continue;
            }
            DB::table('bs_accounts')->insert([
                'tenant_id' => $induk->tenant_id, 'code' => self::KODE, 'name' => 'AKUN MEMO URGENT',
                'type' => 'asset', 'normal_balance' => 'D', 'parent_id' => $induk->id, 'parent_code' => self::INDUK,
                'category_code' => $induk->category_code, 'level' => 6, 'is_postable' => true, 'is_active' => true,
                'cost_center_optional' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Akun yang sudah dipakai jurnal tidak dihapus.
        DB::table('bs_accounts')->where('code', self::KODE)
            ->whereNotIn('id', DB::table('bs_journal_lines')->select('account_id'))->delete();
    }
};

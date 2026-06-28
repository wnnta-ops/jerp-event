<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * #153 — Akun liabilitas "UTANG BG/CEK BEREDAR" (20101010103) agar jurnal BG/Cek KELUAR
 * (serah & cair) bisa terbentuk. Dibuat sebagai anak dari UTANG USAHA (201010101) per-tenant,
 * mengikuti struktur sibling UTANG USAHA SUPPLIER (20101010101).
 */
return new class extends Migration
{
    public function up(): void
    {
        $parents = DB::table('bs_accounts')->where('code', '201010101')->whereNull('deleted_at')->get();
        foreach ($parents as $parent) {
            $exists = DB::table('bs_accounts')->where('code', '20101010103')->where('tenant_id', $parent->tenant_id)->exists();
            if ($exists) {
                continue;
            }
            // Sibling UTANG USAHA SUPPLIER → tiru level/category_code/normal_balance.
            $sib = DB::table('bs_accounts')->where('code', '20101010101')->where('tenant_id', $parent->tenant_id)->first();

            DB::table('bs_accounts')->insert([
                'tenant_id'      => $parent->tenant_id,
                'code'           => '20101010103',
                'name'           => 'UTANG BG/CEK BEREDAR',
                'type'           => 'liability',
                'normal_balance' => $sib->normal_balance ?? 'K',
                'parent_id'      => $parent->id,
                'parent_code'    => $parent->code,
                'category_code'  => $sib->category_code ?? null,
                'level'          => $sib->level ?? 6,
                'is_postable'    => 1,
                'is_active'      => 1,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Hapus hanya bila belum terpakai di jurnal (aman).
        $ids = DB::table('bs_accounts')->where('code', '20101010103')->pluck('id');
        foreach ($ids as $id) {
            if (! DB::table('bs_journal_lines')->where('account_id', $id)->exists()) {
                DB::table('bs_accounts')->where('id', $id)->delete();
            }
        }
    }
};

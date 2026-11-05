<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Memo Urgent tidak disetujui Finance — Finance yang MEMBAYARKAN (TTD "Dibayarkan oleh" di lembar
 * memo, terisi setelah Bayar Memo Urgent). Tahap Finance dihapus dari chain MI-URGENT dan level
 * sesudahnya dirapatkan. Approval yang sudah berjalan tetap memakai snapshot langkahnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        $chains = DB::table('approval_chains')->where('code', 'MI-URGENT')->pluck('id');
        foreach ($chains as $chainId) {
            DB::table('approval_chain_steps')->where('approval_chain_id', $chainId)->where('label', 'Finance')->delete();
            $level = 0;
            $sisa = DB::table('approval_chain_steps')->where('approval_chain_id', $chainId)->orderBy('level')->pluck('id');
            foreach ($sisa as $id) {
                DB::table('approval_chain_steps')->where('id', $id)
                    ->update(['level' => ++$level, 'is_final' => $level === $sisa->count(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Tidak dikembalikan otomatis — tambahkan lagi lewat System › Approval Chain bila perlu.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Numerator SPR — semua No. Kontrak baru kini prefix "SPR-{YYYY}-{seq:5}" (gantikan
 * KPR-/KTR-). Kontrak LAMA tetap nomornya (tidak di-rename) agar referensi FO↔BO
 * (bookings.booking_number = no_kontrak), TTS, Kwitansi, dll tidak putus.
 */
return new class extends Migration
{
    public function up(): void
    {
        $q = DB::table('numerators')->where('tenant_id', 1)->whereNull('company_id')->where('code', 'SPR');
        if ($q->exists()) {
            (clone $q)->update(['format' => 'SPR-{YYYY}-{seq:5}', 'is_active' => true, 'updated_at' => now()]);
        } else {
            DB::table('numerators')->insert([
                'tenant_id'  => 1,
                'company_id' => null,
                'code'       => 'SPR',
                'format'     => 'SPR-{YYYY}-{seq:5}',
                'last_seq'   => 0,
                'period'     => (string) now()->year,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Hapus hanya bila belum dipakai (last_seq=0) agar tak menghilangkan nomor terpakai.
        DB::table('numerators')->where('tenant_id', 1)->whereNull('company_id')
            ->where('code', 'SPR')->where('last_seq', 0)->delete();
    }
};

<?php

namespace App\Modules\FO\MarketingSales\Services;

use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\PengalihanHak;
use Illuminate\Support\Facades\DB;

/**
 * Eksekusi Pengalihan Hak: pindahkan customer kontrak (+reservasi) ke customer baru,
 * lalu simpan record history (lama→baru, biaya admin, scan).
 *
 * @param  array{tgl_pengalihan?:mixed,biaya_admin?:float,file_scan_path?:?string,keterangan?:?string}  $data
 */
class PengalihanHakService
{
    public function transfer(Kontrak $kontrak, int $customerBaruId, array $data = []): PengalihanHak
    {
        return DB::transaction(function () use ($kontrak, $customerBaruId, $data) {
            $kontrak->loadMissing(['reservasi', 'agent']);

            $ph = PengalihanHak::create([
                'tenant_id' => $kontrak->tenant_id ?? 1,
                'kontrak_id' => $kontrak->id,
                'customer_lama_id' => $kontrak->customer_id,
                'customer_baru_id' => $customerBaruId,
                'tgl_pengalihan' => $data['tgl_pengalihan'] ?? now()->toDateString(),
                'biaya_admin' => (float) ($data['biaya_admin'] ?? 0),
                'file_scan_path' => $data['file_scan_path'] ?? null,
                'sales_snapshot' => $kontrak->agent?->name,
                'status' => 'SELESAI',
                'keterangan' => $data['keterangan'] ?? null,
            ]);

            // Pindahkan kepemilikan: kontrak + reservasi → customer baru.
            $kontrak->update(['customer_id' => $customerBaruId]);
            if ($kontrak->reservasi) {
                $kontrak->reservasi->update(['customer_id' => $customerBaruId]);
            }

            return $ph;
        });
    }
}

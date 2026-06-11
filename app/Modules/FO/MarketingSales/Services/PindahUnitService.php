<?php

namespace App\Modules\FO\MarketingSales\Services;

use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\PindahUnit;
use App\Modules\FO\MarketingSales\Models\Unit;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Eksekusi Pindah Unit: ganti unit kontrak (+reservasi) ke unit baru. Unit lama
 * dikembalikan TERSEDIA, unit baru ditandai terjual/booking sesuai tahap kontrak.
 * Nilai kontrak (financial) tidak diubah — bila perlu sesuaikan via Adjustment Kontrak.
 *
 * @param  array{tgl_pindah?:mixed,biaya_admin?:float,keterangan?:?string}  $data
 */
class PindahUnitService
{
    public function move(Kontrak $kontrak, int $unitBaruId, array $data = []): PindahUnit
    {
        return DB::transaction(function () use ($kontrak, $unitBaruId, $data) {
            $kontrak->loadMissing('reservasi');

            $unitLamaId = $kontrak->unit_id;
            $unitBaru = Unit::lockForUpdate()->findOrFail($unitBaruId);
            if ($unitBaru->status === 'TERJUAL') {
                throw new RuntimeException('Unit '.($unitBaru->kode_unit ?: $unitBaru->no_unit).' sudah terjual.');
            }
            if ($unitBaruId === $unitLamaId) {
                throw new RuntimeException('Unit baru sama dengan unit lama.');
            }

            $pu = PindahUnit::create([
                'tenant_id' => $kontrak->tenant_id ?? 1,
                'kontrak_id' => $kontrak->id,
                'customer_id' => $kontrak->customer_id,
                'unit_lama_id' => $unitLamaId,
                'unit_baru_id' => $unitBaruId,
                'tgl_pindah' => $data['tgl_pindah'] ?? now()->toDateString(),
                'biaya_admin' => (float) ($data['biaya_admin'] ?? 0),
                'status' => 'SELESAI',
                'keterangan' => $data['keterangan'] ?? null,
            ]);

            // Unit lama kembali TERSEDIA; unit baru ikut tahap kontrak (SPR=TERJUAL, SPRS=BOOKING).
            if ($unitLamaId && $unitLama = Unit::find($unitLamaId)) {
                $unitLama->update(['status' => 'TERSEDIA']);
            }
            $unitBaru->update(['status' => $kontrak->tahap === 'SPR' ? 'TERJUAL' : 'BOOKING']);

            $kontrak->update(['unit_id' => $unitBaruId]);
            if ($kontrak->reservasi) {
                $kontrak->reservasi->update(['unit_id' => $unitBaruId]);
            }

            return $pu;
        });
    }
}

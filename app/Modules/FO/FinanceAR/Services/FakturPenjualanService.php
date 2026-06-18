<?php

namespace App\Modules\FO\FinanceAR\Services;

use App\Modules\FO\FinanceAR\Models\Fp;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Services\Numerator\NumeratorServiceInterface;

/**
 * Faktur Penjualan otomatis: dibuat saat unit TERJUAL (kontrak naik ke SPR).
 * Idempotent — satu Faktur Penjualan per kontrak.
 */
class FakturPenjualanService
{
    public static function ensureForKontrak(Kontrak $kontrak): ?Fp
    {
        $existing = Fp::query()->where('kontrak_id', $kontrak->id)->first();
        if ($existing) {
            return $existing;
        }

        $kontrak->loadMissing('unit');

        return Fp::create([
            'tenant_id' => $kontrak->tenant_id,
            'no_fp' => app(NumeratorServiceInterface::class)->next('FP'),
            'tgl_faktur' => now()->toDateString(),
            'tgl_terima' => now()->toDateString(),
            'kontrak_id' => $kontrak->id,
            'project_id' => $kontrak->unit?->project_id,
            'status' => 'TERBIT',
            'keterangan' => 'Faktur Penjualan otomatis — '.$kontrak->no_kontrak,
        ]);
    }
}

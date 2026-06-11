<?php

namespace App\Modules\FO\Komisi\Services;

use App\Modules\FO\Komisi\Models\SkemaBlt;
use App\Modules\FO\MarketingSales\Models\Kontrak;

/**
 * Hitung BLT per level dari Kontrak + Skema BLT (selaras docs/Rumus All.csv & NOTE):
 *   NILAI BLT = tarif baris skema (RP flat, atau % dari basis)
 *   BLT NETT  = NILAI BLT − PPh   (dibulatkan KE ATAS)
 * PPh per type:
 *   - Sales (perorangan, PPh 21): ≤25jt → 2,5% ; >25jt → 50%×5%
 *   - Koordinator (perorangan, PPh 21): 2,5%
 *   - Kantor (perusahaan, PPh 23): 2%
 */
class BltCalculatorService
{
    /** @return array<int,array<string,mixed>> satu entri per baris/level skema. */
    public function generate(Kontrak $kontrak, SkemaBlt $skema): array
    {
        $total = (float) $kontrak->total_kontrak;
        $basis = $skema->dasar_hitung === 'NILAI_KONTRAK' ? $total : (float) ceil($total / 1.11);

        $rows = [];
        foreach ($skema->lines as $line) {
            $nilai = strtoupper((string) $line->tipe_tarif) === 'PERSEN'
                ? (float) ceil($basis * (float) $line->tarif / 100)
                : (float) $line->tarif;

            $levelName = (string) ($line->salesLevel?->name ?? '');
            $pph = $this->pph($nilai, $levelName);

            $rows[] = [
                'sales_level_id' => $line->sales_level_id,
                'level_type' => $pph['level_type'],
                'nilai_blt' => $nilai,
                'pph_jenis' => $pph['jenis'],
                'pph_persen' => $pph['persen'],
                'pph_amount' => $pph['amount'],
                'nett' => $nilai - $pph['amount'],
            ];
        }

        return $rows;
    }

    /** Potongan PPh + klasifikasi level. */
    public function pph(float $nilai, string $levelName): array
    {
        $name = strtoupper($levelName);

        if (str_contains($name, 'KANTOR')) {
            return ['level_type' => 'KANTOR', 'jenis' => 'PPh23', 'persen' => 2.0, 'amount' => (float) ceil($nilai * 0.02)];
        }
        if (str_contains($name, 'KOOR')) {
            return ['level_type' => 'KOORDINATOR', 'jenis' => 'PPh21', 'persen' => 2.5, 'amount' => (float) ceil($nilai * 0.025)];
        }
        // Sales (perorangan, PPh 21)
        if ($nilai > 25_000_000) {
            return ['level_type' => 'SALES', 'jenis' => 'PPh21', 'persen' => 2.5, 'amount' => (float) ceil($nilai * 0.5 * 0.05)];
        }

        return ['level_type' => 'SALES', 'jenis' => 'PPh21', 'persen' => 2.5, 'amount' => (float) ceil($nilai * 0.025)];
    }

    /** Skema BLT paling spesifik untuk kontrak (project/tipe sales/tipe unit). */
    public function matchSkema(Kontrak $kontrak): ?SkemaBlt
    {
        $kontrak->loadMissing(['unit', 'agent']);

        return SkemaBlt::with(['lines.salesLevel'])
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $kontrak->unit?->project_id))
            ->where(fn ($q) => $q->whereNull('sales_type_id')->orWhere('sales_type_id', $kontrak->agent?->sales_type_id))
            ->where(fn ($q) => $q->whereNull('unit_type_id')->orWhere('unit_type_id', $kontrak->unit?->unit_type_id))
            ->orderByRaw('(project_id IS NOT NULL) + (sales_type_id IS NOT NULL) + (unit_type_id IS NOT NULL) DESC')
            ->first();
    }
}

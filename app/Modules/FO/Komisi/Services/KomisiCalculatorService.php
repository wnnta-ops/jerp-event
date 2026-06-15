<?php

namespace App\Modules\FO\Komisi\Services;

use App\Modules\FO\Komisi\Models\SkemaKomisi;
use App\Modules\FO\MarketingSales\Models\Kontrak;

/**
 * Hitung komisi agent dari Kontrak + Skema Komisi (selaras docs/Rumus All.csv):
 *   basis  = dasar_hitung (DPP = Nilai Kontrak / 1,11  ATAU  Nilai Kontrak)
 *   biaya  = Σ TipeTarif skema  (BPHTB = (basis − pengurang) × %, PPh = basis × %, dst)
 *   nett   = basis − biaya
 *   komisi = nett × rate(level)        ← rate dari baris Skema (per level agent)
 *   pph    = komisi × pph%             ← PPh 23 (default 2%)
 *   komisiNett = komisi − pph
 * SEMUA hasil dibulatkan KE ATAS (ceil).
 */
class KomisiCalculatorService
{
    public function calculate(Kontrak $kontrak, SkemaKomisi $skema, float $ratePersen, float $pphPersen = 2.0): array
    {
        $total = (float) $kontrak->total_kontrak;

        // DPP (basis): tarif dengan SATUAN '÷' (BAGI) = pembagi → DPP = harga ÷ nilai
        // (mis. 1,11). Bila tak ada, fallback ke dasar_hitung skema.
        $pembagi = 0.0;
        foreach ($skema->biaya as $b) {
            $tt = $b->tipeTarif;
            if ($tt && (strtoupper((string) $tt->satuan) === 'BAGI' || strtoupper((string) $tt->dikenakan) === 'BASIS')) {
                $pembagi = (float) $tt->nilai_biaya;
            }
        }
        $basis = $pembagi > 0
            ? (float) ceil($total / $pembagi)
            : ($skema->dasar_hitung === 'NILAI_KONTRAK' ? $total : (float) ceil($total / 1.11));

        // Biaya dari Setup Tarif (di-link ke skema):
        //  - dikenakan=DPP    → potong basis (BPHTB, SSP)
        //  - dikenakan=KOMISI → PPh atas komisi (PPh 23), dijumlahkan jadi % pph
        $biayaDetail = [];
        $biayaTotal = 0.0;
        $pphFromTarif = null;
        foreach ($skema->biaya as $b) {
            $tt = $b->tipeTarif;
            if (! $tt) {
                continue;
            }
            $satuan = strtoupper((string) $tt->satuan);
            $dikenakan = strtoupper((string) ($tt->dikenakan ?? 'DPP'));
            if ($satuan === 'BAGI' || $dikenakan === 'BASIS') {
                continue; // pembagi DPP — sudah dipakai untuk derivasi basis
            }
            if ($dikenakan === 'KOMISI') {
                $pphFromTarif = (float) ($pphFromTarif ?? 0) + (float) $tt->nilai_biaya;

                continue;
            }
            $isPersen = in_array($satuan, ['PERSEN', '%', 'PERCENT'], true);
            $amt = $isPersen
                ? max(0.0, $basis - (float) $tt->nilai_pengurang) * (float) $tt->nilai_biaya / 100
                : (float) $tt->nilai_biaya;
            $amt = (float) ceil($amt);
            $biayaDetail[$tt->nama] = $amt;
            $biayaTotal += $amt;
        }

        // PPh: pakai tarif KOMISI dari Setup Tarif bila di-link; else default param.
        $effPph = $pphFromTarif ?? $pphPersen;

        $hargaNett = max(0.0, $basis - $biayaTotal);
        $komisi = (float) ceil($hargaNett * $ratePersen / 100);
        $pph = (float) ceil($komisi * $effPph / 100);
        $nett = $komisi - $pph;

        return [
            'basis' => $basis,
            'biaya_detail' => $biayaDetail,
            'biaya_total' => $biayaTotal,
            'harga_nett' => $hargaNett,
            'rate_persen' => $ratePersen,
            'komisi' => $komisi,
            'pph_persen' => $effPph,
            'pph' => $pph,
            'nett' => $nett,
        ];
    }

    /** Skema paling spesifik yang cocok untuk kontrak (project/tipe sales/lokasi/tahap). */
    public function matchSkema(Kontrak $kontrak): ?SkemaKomisi
    {
        $kontrak->loadMissing(['unit', 'agent']);
        $projectId = $kontrak->unit?->project_id;
        $salesTypeId = $kontrak->agent?->sales_type_id;
        $lokasiId = $kontrak->unit?->lokasi_id;
        $tahapId = $kontrak->unit?->tahap_id;

        return SkemaKomisi::with('biaya.tipeTarif', 'lines')
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $projectId))
            ->where(fn ($q) => $q->whereNull('sales_type_id')->orWhere('sales_type_id', $salesTypeId))
            ->where(fn ($q) => $q->whereNull('lokasi_id')->orWhere('lokasi_id', $lokasiId))
            ->where(fn ($q) => $q->whereNull('tahap_id')->orWhere('tahap_id', $tahapId))
            ->orderByRaw('(project_id IS NOT NULL) + (sales_type_id IS NOT NULL) + (lokasi_id IS NOT NULL) + (tahap_id IS NOT NULL) DESC')
            ->first();
    }

    /** Rate (% / nominal) untuk level agent dari baris skema; fallback baris pertama. */
    public function rateForAgent(SkemaKomisi $skema, ?int $salesLevelId): float
    {
        $line = $skema->lines->firstWhere('sales_level_id', $salesLevelId) ?? $skema->lines->first();

        return $line ? (float) $line->tarif : 0.0;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Tax\Services;

/**
 * BPHTB (Bea Perolehan Hak atas Tanah & Bangunan) Calculator.
 *
 * Formula:
 *   Total NJOP = (Luas Tanah × NJOP/m²) + (Luas Bangunan × NJOP/m²)
 *   NPOP       = MAX(Nilai AJB, Total NJOP)
 *   NPOPKP     = MAX(0, NPOP − NPOTKP)          ← NPOTKP = threshold regional
 *   BPHTB      = NPOPKP × 5%
 *
 * NPOTKP default = Rp 80,000,000 (DKI Jakarta & Banten standard).
 * Beberapa daerah pakai threshold berbeda — selalu pass eksplisit.
 */
final class BphtbCalculator
{
    public const DEFAULT_NPOTKP = 80_000_000;
    public const BPHTB_RATE     = 0.05;

    /**
     * @param array{
     *   land_area: int|float,
     *   building_area: int|float,
     *   njop_per_m2_land: int|float,
     *   njop_per_m2_building: int|float,
     *   ajb_value: int|float,
     *   npotkp?: int|float,
     * } $input
     *
     * @return array{
     *   njop_tanah: float, njop_bangunan: float, total_njop: float,
     *   npop: float, npotkp: float, npopkp: float, bphtb: float, rate: float
     * }
     */
    public function calculate(array $input): array
    {
        $luasTanah    = (float) ($input['land_area'] ?? 0);
        $luasBangunan = (float) ($input['building_area'] ?? 0);
        $njopTanahM   = (float) ($input['njop_per_m2_land'] ?? 0);
        $njopBangM    = (float) ($input['njop_per_m2_building'] ?? 0);
        $ajbValue     = (float) ($input['ajb_value'] ?? 0);
        $npotkp       = (float) ($input['npotkp'] ?? self::DEFAULT_NPOTKP);

        $njopTanah    = $luasTanah * $njopTanahM;
        $njopBangunan = $luasBangunan * $njopBangM;
        $totalNjop    = $njopTanah + $njopBangunan;
        $npop         = max($ajbValue, $totalNjop);
        $npopkp       = max(0, $npop - $npotkp);
        $bphtb        = $npopkp * self::BPHTB_RATE;

        return [
            'njop_tanah'    => round($njopTanah, 2),
            'njop_bangunan' => round($njopBangunan, 2),
            'total_njop'    => round($totalNjop, 2),
            'npop'          => round($npop, 2),
            'npotkp'        => round($npotkp, 2),
            'npopkp'        => round($npopkp, 2),
            'bphtb'         => round($bphtb, 2),
            'rate'          => self::BPHTB_RATE,
        ];
    }
}

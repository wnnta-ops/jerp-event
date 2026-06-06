<?php

namespace App\Modules\FO\Adminjual\Services;

use App\Modules\FO\Adminjual\Models\Skema;

/**
 * Kalkulator rincian rupiah skema bayar (Gambar 2): menerjemahkan rumus per
 * mode "Booking Fee dipotong di" untuk satu harga unit.
 *
 * Skema disimpan sebagai TEMPLATE (rumus/persen). Nominal rupiah dihitung di
 * sini saat skema diterapkan ke unit (harga dari price list) atau untuk
 * preview di wizard (memakai Present Value).
 *
 * Istilah:
 *  - UTJ      : Booking Fee (dari pembayaran Xendit awal / transfer manual).
 *  - Subsidi  : "Free X% dari developer" = DP × subsidi% (mode DP Disebar).
 *  - Plafond  : Pencairan KPR (dibayar bank).
 *
 * @phpstan-type BreakdownLine array{label:string,tipe:string,nominal:float,info:bool,keterangan:string}
 * @phpstan-type Breakdown array{harga:float,mode:string,lines:array<int,BreakdownLine>,plafond_kpr:float,total_customer:float}
 */
class SkemaCalculatorService
{
    /**
     * Rincian dari sebuah Skema tersimpan + harga unit.
     *
     * @return array<string,mixed>
     */
    public function breakdown(Skema $skema, float $harga): array
    {
        $skema->loadMissing('lines');

        return $this->compute([
            'mode' => $skema->bf_dipotong_di ?? 'TIDAK_DISEBAR',
            'cara_bayar' => $skema->cara_bayar ?? 'KPR',
            'utj' => (float) $skema->bf_nominal,
            'dp_persen' => (float) $skema->dp_persen,
            'ang_persen' => (float) $skema->ang_persen,
            'subsidi_persen' => (float) $skema->subsidi_persen,
            'n_dp' => max(1, $skema->lines->where('tipe', 'DP')->count()),
            'n_ang' => max(1, $skema->lines->where('tipe', 'ANG')->count()),
        ], $harga);
    }

    /**
     * Rincian dari parameter rumus mentah (untuk preview wizard sebelum skema disimpan).
     *
     * @param  array<string,mixed>  $params  mode, cara_bayar, utj, dp_persen, ang_persen, subsidi_persen, n_dp, n_ang
     * @return array<string,mixed>
     */
    public function compute(array $params, float $harga): array
    {
        $mode = (string) ($params['mode'] ?? 'TIDAK_DISEBAR');
        $caraBayar = (string) ($params['cara_bayar'] ?? 'KPR');
        $utj = (float) ($params['utj'] ?? 0);
        $dpPct = (float) ($params['dp_persen'] ?? 0);
        $angPct = (float) ($params['ang_persen'] ?? 0);
        $subPct = (float) ($params['subsidi_persen'] ?? 0);
        $nDp = max(1, (int) ($params['n_dp'] ?? 1));
        $nAng = max(1, (int) ($params['n_ang'] ?? 1));

        return match ($mode) {
            'DP_DISEBAR' => $this->dpDisebar($harga, $dpPct, $subPct, $utj, $nDp),
            'ANGSURAN_DISEBAR' => $this->angsuranDisebar($harga, $utj, $nAng),
            'ANGSURAN1' => $this->freeDp($harga, $dpPct, $utj),
            'DP1' => $this->dpSatu($harga, $dpPct, $angPct, $utj, $nDp, $nAng, $caraBayar),
            default => $this->tidakDisebar($harga, $dpPct, $angPct, $utj, $nDp, $nAng, $caraBayar),
        };
    }

    /**
     * DP DISEBAR — KPR DP 3X (UTJ tidak mengurangi plafon).
     * B = harga × dp%  →  C = B × subsidi%  →  D = B−C  →  F = D−UTJ  →  DP/n
     * Plafond = harga − F.
     *
     * @return array<string,mixed>
     */
    private function dpDisebar(float $harga, float $dpPct, float $subPct, float $utj, int $nDp): array
    {
        $dpGross = $this->r($harga * $dpPct / 100);          // B
        $subsidi = $this->r($dpGross * $subPct / 100);       // C
        $dpNet = $this->r($dpGross - $subsidi);              // D (subsidi DP3X)
        $dpBayar = $this->r($dpNet - $utj);                  // F (DP3X dibayar customer)
        $perDp = $this->r($dpBayar / max(1, $nDp));
        $plafond = $this->r($harga - $dpBayar);              // H (pencairan KPR)

        $lines = [
            $this->line('UTJ (Booking Fee)', 'BF', $utj, false, 'Tidak mengurangi plafon'),
            $this->line('Subsidi developer ('.$this->trimPct($subPct).'%)', 'SUB', $subsidi, true, 'Free dari developer, mengurangi DP'),
        ];
        $lines = array_merge($lines, $this->spread('DP', $dpBayar, $nDp));
        $lines[] = $this->line('PENCAIRAN KPR', 'KPR', $plafond, true, 'Dibayar bank (plafond)');

        return $this->wrap($harga, 'DP_DISEBAR', $lines, $plafond, $this->r($utj + $dpBayar));
    }

    /**
     * ANGSURAN DISEBAR — CASH / INHOUSE: (harga − UTJ) dibagi rata ke n angsuran.
     *
     * @return array<string,mixed>
     */
    private function angsuranDisebar(float $harga, float $utj, int $nAng): array
    {
        $sisa = $this->r($harga - $utj);
        $lines = [$this->line('UTJ (Booking Fee)', 'BF', $utj, false, '')];
        $lines = array_merge($lines, $this->spread('ANGSURAN', $sisa, $nAng));

        return $this->wrap($harga, 'ANGSURAN_DISEBAR', $lines, 0.0, $harga);
    }

    /**
     * ANGSURAN 1 — FREE DP: DP (harga × dp%) gratis dari developer.
     * Plafond = harga − DP − UTJ. Customer hanya bayar UTJ di muka.
     *
     * @return array<string,mixed>
     */
    private function freeDp(float $harga, float $dpPct, float $utj): array
    {
        $dp = $this->r($harga * $dpPct / 100);
        $plafond = $this->r($harga - $dp - $utj);
        $lines = [
            $this->line('UTJ (Booking Fee)', 'BF', $utj, false, ''),
            $this->line('DP ('.$this->trimPct($dpPct).'%) — FREE', 'DP', $dp, true, 'Free dari developer'),
            $this->line('PENCAIRAN KPR', 'KPR', $plafond, true, 'Dibayar bank (plafond)'),
        ];

        return $this->wrap($harga, 'ANGSURAN1', $lines, $plafond, $utj);
    }

    /**
     * BF dipotong di DP 1: UTJ dikreditkan ke DP pertama.
     *
     * @return array<string,mixed>
     */
    private function dpSatu(float $harga, float $dpPct, float $angPct, float $utj, int $nDp, int $nAng, string $caraBayar): array
    {
        $dpTotal = $this->r($harga * $dpPct / 100);
        $dpLines = $this->spread('DP', $dpTotal, $nDp);
        // UTJ kredit ke DP 1.
        if (isset($dpLines[0])) {
            $dpLines[0]['nominal'] = $this->r($dpLines[0]['nominal'] - $utj);
            $dpLines[0]['keterangan'] = 'Dikurangi UTJ '.$this->money($utj);
        }
        $lines = array_merge([$this->line('UTJ (Booking Fee)', 'BF', $utj, false, 'Dipotong di DP 1')], $dpLines);

        return $this->appendAngsuran($harga, $angPct, $dpTotal, $nAng, $caraBayar, $lines, $utj, true);
    }

    /**
     * BF tidak disebar: UTJ berdiri sendiri; DP & Angsuran penuh sesuai persen.
     *
     * @return array<string,mixed>
     */
    private function tidakDisebar(float $harga, float $dpPct, float $angPct, float $utj, int $nDp, int $nAng, string $caraBayar): array
    {
        $dpTotal = $this->r($harga * $dpPct / 100);
        $lines = array_merge(
            [$this->line('UTJ (Booking Fee)', 'BF', $utj, false, '')],
            $this->spread('DP', $dpTotal, $nDp),
        );

        return $this->appendAngsuran($harga, $angPct, $dpTotal, $nAng, $caraBayar, $lines, $utj, false);
    }

    /**
     * Tambahkan komponen angsuran: KPR → satu baris PENCAIRAN KPR (sisa harga−DP),
     * non-KPR → n angsuran dari (harga × ang%).
     *
     * @param  array<int,array<string,mixed>>  $lines
     * @return array<string,mixed>
     */
    private function appendAngsuran(float $harga, float $angPct, float $dpTotal, int $nAng, string $caraBayar, array $lines, float $utj, bool $utjInDp): array
    {
        if ($caraBayar === 'KPR') {
            $plafond = $this->r($harga - $dpTotal);
            $lines[] = $this->line('PENCAIRAN KPR', 'KPR', $plafond, true, 'Dibayar bank (plafond)');
            $custDp = $utjInDp ? $dpTotal : $this->r($dpTotal + $utj);

            return $this->wrap($harga, 'KPR', $lines, $plafond, $custDp);
        }

        $angTotal = $this->r($harga * $angPct / 100);
        $lines = array_merge($lines, $this->spread('ANGSURAN', $angTotal, $nAng));
        $customer = $utjInDp ? $this->r($dpTotal + $angTotal) : $this->r($utj + $dpTotal + $angTotal);

        return $this->wrap($harga, 'CASH', $lines, 0.0, $customer);
    }

    /**
     * Bagi total rupiah ke n baris bernama "$label N"; sisa pembulatan ke baris terakhir.
     *
     * @return array<int,array<string,mixed>>
     */
    private function spread(string $label, float $total, int $n): array
    {
        $n = max(1, $n);
        $each = $this->r($total / $n);
        $lines = [];
        for ($i = 1; $i <= $n; $i++) {
            $nominal = $i === $n ? $this->r($total - $each * ($n - 1)) : $each;
            $lines[] = $this->line($label.' '.$i, $label === 'DP' ? 'DP' : 'ANG', $nominal, false, '');
        }

        return $lines;
    }

    /**
     * @return array<string,mixed>
     */
    private function line(string $label, string $tipe, float $nominal, bool $info, string $keterangan): array
    {
        return ['label' => $label, 'tipe' => $tipe, 'nominal' => $this->r($nominal), 'info' => $info, 'keterangan' => $keterangan];
    }

    /**
     * @param  array<int,array<string,mixed>>  $lines
     * @return array<string,mixed>
     */
    private function wrap(float $harga, string $mode, array $lines, float $plafond, float $totalCustomer): array
    {
        return [
            'harga' => $this->r($harga),
            'mode' => $mode,
            'lines' => array_values($lines),
            'plafond_kpr' => $this->r($plafond),
            'total_customer' => $this->r($totalCustomer),
        ];
    }

    private function r(float $v): float
    {
        return round($v, 2);
    }

    private function trimPct(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    private function money(float $v): string
    {
        return 'Rp '.number_format($v, 0, ',', '.');
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Sales\Pricing\Services;

use Illuminate\Support\Carbon;

/**
 * Builder Skema Cara Bayar (eProperty: Setup Sales → Skema Cara Bayar).
 *
 * Menerjemahkan "Rumus Global / Nilai / Jadwal" menjadi jadwal termin konkret.
 *   - Rumus Global : present value, diskon bertingkat, booking fee + treatment.
 *   - Rumus Nilai  : Booking Fee / DP (n×nilai) / Angsuran (n×nilai), basis %/Rp.
 *   - Rumus Jadwal : jatuh tempo pertama + interval (bulan/hari).
 *
 * Pure (tanpa DB) supaya dipakai untuk preview live maupun generate template termin.
 */
class PaymentSchemeBuilder
{
    /**
     * Hitung PV bersih setelah diskon bertingkat.
     *
     * @param  array<int,float|int|string>  $tieredDiscounts
     */
    public function netPresentValue(float $pv, array $tieredDiscounts = []): float
    {
        $net = $pv;
        foreach ($tieredDiscounts as $pct) {
            $net *= (1 - ((float) $pct) / 100);
        }

        return round($net, 2);
    }

    /**
     * Simulasikan jadwal pembayaran untuk PV tertentu.
     *
     * @param  array<string,mixed>  $r       rumus skema (kolom payment_schemes)
     * @return array<string,mixed>           {rows, totals, pv_gross, pv_net, discount_steps}
     */
    public function simulate(array $r, float $pv, ?string $startDate = null): array
    {
        $tiered  = $this->normalizeTiered($r['tiered_discounts'] ?? []);
        $pvNet   = $this->netPresentValue($pv, $tiered);
        $start   = $startDate ? Carbon::parse($startDate) : Carbon::now();

        $bf        = (float) ($r['booking_fee'] ?? 0);
        $treatment = (string) ($r['bf_treatment'] ?? 'tidak');
        $unit      = ($r['schedule_unit'] ?? 'month') === 'day' ? 'day' : 'month';
        $first     = max(0, (int) ($r['schedule_first'] ?? 1));
        $interval  = max(1, (int) ($r['schedule_interval'] ?? 1));

        $dpCount = max(0, (int) ($r['dp_count'] ?? 0));
        $dpEach  = $this->amount((string) ($r['dp_basis'] ?? 'percent'), (float) ($r['dp_value'] ?? 0), $pvNet);
        $inCount = max(0, (int) ($r['inst_count'] ?? 0));
        $inEach  = $this->amount((string) ($r['inst_basis'] ?? 'percent'), (float) ($r['inst_value'] ?? 0), $pvNet);

        // Per-installment amounts (sebelum treatment booking fee).
        $dps  = array_fill(0, $dpCount, $dpEach);
        $ins  = array_fill(0, $inCount, $inEach);

        // Terapkan treatment booking fee: BF dikreditkan ke DP/Angsuran tertentu
        // (mengurangi nominalnya) namun tetap muncul sebagai baris BF tersendiri.
        switch ($treatment) {
            case 'dp1':              if ($dpCount) { $dps[0] = max(0, $dps[0] - $bf); } break;
            case 'angsuran1':        if ($inCount) { $ins[0] = max(0, $ins[0] - $bf); } break;
            case 'dp_disebar':       if ($dpCount) { foreach ($dps as $i => $v) { $dps[$i] = max(0, $v - $bf / $dpCount); } } break;
            case 'angsuran_disebar': if ($inCount) { foreach ($ins as $i => $v) { $ins[$i] = max(0, $v - $bf / $inCount); } } break;
            default:                 break; // 'tidak' — BF tidak dipotong dari termin manapun
        }

        $rows = [];
        $no   = 1;

        // Booking Fee (selalu jatuh tempo saat booking, hari ke-0).
        if ($bf > 0) {
            $rows[] = $this->row($no++, 'Booking Fee', $start->copy(), 0, $bf, false, 'bf');
        }

        // Jadwal DP lalu Angsuran mengikuti satu kadens.
        $seq = 0;
        foreach ($dps as $i => $amt) {
            $offset = $first + $seq * $interval;
            $rows[] = $this->row($no++, 'DP-'.($i + 1), $this->due($start, $unit, $offset), $this->days($unit, $offset), $amt, true, 'dp');
            $seq++;
        }
        foreach ($ins as $i => $amt) {
            $offset = $first + $seq * $interval;
            $rows[] = $this->row($no++, 'Angsuran-'.($i + 1), $this->due($start, $unit, $offset), $this->days($unit, $offset), $amt, false, 'angsuran');
            $seq++;
        }

        $dpTotal  = array_sum($dps);
        $inTotal  = array_sum($ins);
        // Total dijadwalkan = jumlah seluruh baris (BF + DP + Angsuran) agar tabel
        // preview selalu konsisten dengan totalnya.
        $scheduled = ($bf > 0 ? $bf : 0.0) + $dpTotal + $inTotal;
        $bank      = round($pvNet - $scheduled, 2);           // sisa → KPR/KPA (bila ada)

        return [
            'pv_gross'       => round($pv, 2),
            'pv_net'         => $pvNet,
            'discount_steps' => $tiered,
            'rows'           => $rows,
            'totals'         => [
                'booking_fee'     => round($bf, 2),
                'dp_total'        => round($dpTotal, 2),
                'inst_total'      => round($inTotal, 2),
                'scheduled_total' => round($scheduled, 2),
                'bank_portion'    => $bank,
            ],
        ];
    }

    /**
     * Template termin untuk disimpan (payment_scheme_terms) — normalisasi rumus.
     * Disimpan apa adanya (basis %/Rp) agar reusable lintas unit.
     *
     * @param  array<string,mixed>  $r
     * @return array<int,array<string,mixed>>
     */
    public function template(array $r): array
    {
        $unit     = ($r['schedule_unit'] ?? 'month') === 'day' ? 'day' : 'month';
        $first    = max(0, (int) ($r['schedule_first'] ?? 1));
        $interval = max(1, (int) ($r['schedule_interval'] ?? 1));
        $dpCount  = max(0, (int) ($r['dp_count'] ?? 0));
        $inCount  = max(0, (int) ($r['inst_count'] ?? 0));
        $bf       = (float) ($r['booking_fee'] ?? 0);

        $terms = [];
        $no    = 1;
        if ($bf > 0) {
            $terms[] = ['term_no' => $no++, 'label' => 'Booking Fee', 'basis' => 'amount', 'value' => round($bf, 2), 'due_after_days' => 0, 'is_dp' => false];
        }
        $seq = 0;
        for ($i = 1; $i <= $dpCount; $i++) {
            $terms[] = ['term_no' => $no++, 'label' => 'DP-'.$i, 'basis' => (string) ($r['dp_basis'] ?? 'percent'), 'value' => round((float) ($r['dp_value'] ?? 0), 2), 'due_after_days' => $this->days($unit, $first + $seq * $interval), 'is_dp' => true];
            $seq++;
        }
        for ($i = 1; $i <= $inCount; $i++) {
            $terms[] = ['term_no' => $no++, 'label' => 'Angsuran-'.$i, 'basis' => (string) ($r['inst_basis'] ?? 'percent'), 'value' => round((float) ($r['inst_value'] ?? 0), 2), 'due_after_days' => $this->days($unit, $first + $seq * $interval), 'is_dp' => false];
            $seq++;
        }

        return $terms;
    }

    private function amount(string $basis, float $value, float $pvNet): float
    {
        return $basis === 'percent' ? round($pvNet * $value / 100, 2) : round($value, 2);
    }

    private function due(Carbon $start, string $unit, int $offset): Carbon
    {
        return $unit === 'day' ? $start->copy()->addDays($offset) : $start->copy()->addMonthsNoOverflow($offset);
    }

    private function days(string $unit, int $offset): int
    {
        return $unit === 'day' ? $offset : $offset * 30;
    }

    /** @return array<int,float> */
    private function normalizeTiered(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true) ?: [];
        }

        return collect(is_array($raw) ? $raw : [])
            ->map(fn ($v) => (float) $v)->filter(fn ($v) => $v > 0)->values()->all();
    }

    /** @return array<string,mixed> */
    private function row(int $no, string $label, Carbon $due, int $days, float $amount, bool $isDp, string $kind): array
    {
        return [
            'no' => $no, 'label' => $label, 'due_date' => $due->toDateString(),
            'due_after_days' => $days, 'amount' => round($amount, 2), 'is_dp' => $isDp, 'kind' => $kind,
        ];
    }
}

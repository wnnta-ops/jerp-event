<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsBudget;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Carbon;

/**
 * Gerbang over-budget: mencegah input yang membuat realisasi melewati Anggaran (approved) per
 * (akun + cost center + tahun). Cost center efektif per baris = baris → fallback header jurnal.
 * Dipakai alur AP: Registrasi Invoice Hutang, Penghapusan Nilai Hutang, Posting Invoice.
 */
final class BudgetGuardService
{
    public function __construct(private readonly BudgetRealizationService $realisasi) {}

    /**
     * @param  array<int,array<string,mixed>>  $lines  baris jurnal (account_id, cost_center_id, debit, credit)
     * @param  ?int  $headerCostCenterId  cost center header (fallback bila baris tak punya)
     *
     * @throws BusinessRuleException bila ada (akun+cost center) yang melebihi anggaran
     */
    public function assertWithinBudget(array $lines, ?int $headerCostCenterId, string $journalDate): void
    {
        $year = (int) Carbon::parse($journalDate)->year;

        // Gabung tambahan realisasi per (akun, cost center) — beberapa baris bisa akun sama.
        $add = [];
        foreach ($lines as $l) {
            $accountId = (int) ($l['account_id'] ?? 0);
            $cc        = (int) (($l['cost_center_id'] ?? null) ?: $headerCostCenterId ?: 0);
            if ($accountId <= 0 || $cc <= 0) {
                continue; // tanpa akun / cost center tidak bisa dicek per-cost-center
            }
            $delta = $this->realisasi->contribution($accountId, (float) ($l['debit'] ?? 0), (float) ($l['credit'] ?? 0));
            if ($delta <= 0) {
                continue; // hanya baris yang MENAMBAH realisasi yang bisa over-budget
            }
            $add[$accountId.'|'.$cc] = ($add[$accountId.'|'.$cc] ?? 0.0) + $delta;
        }

        foreach ($add as $key => $delta) {
            [$accountId, $cc] = array_map('intval', explode('|', $key));
            $budget = BsBudget::where('year', $year)->where('account_id', $accountId)
                ->where('cost_center_id', $cc)->where('status', 'approved')->first();
            if (! $budget) {
                continue; // tidak ada anggaran disetujui utk (akun + cost center) → tidak dibatasi
            }
            $cap  = round((float) $budget->amount, 2);
            $used = $this->realisasi->total($year, $accountId, $cc);
            if (round($used + $delta, 2) > $cap + 0.001) {
                $acc = BsAccount::find($accountId);
                $rp  = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
                throw new BusinessRuleException(
                    'Melebihi anggaran '.$year.' untuk akun '.$acc?->code.' '.$acc?->name.': anggaran '.$rp($cap)
                    .', realisasi '.$rp($used).', tambahan '.$rp($delta).' → sisa hanya '.$rp(max(0, $cap - $used))
                    .'. Input ditolak.',
                    errorCode: 'OVER_BUDGET',
                );
            }
        }
    }
}

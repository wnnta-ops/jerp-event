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
    public function assertWithinBudget(array $lines, ?int $headerCostCenterId, string $journalDate, ?int $excludeJournalId = null): void
    {
        $year = (int) Carbon::parse($journalDate)->year;

        // Kontribusi NETTO (debit − credit, bertanda sesuai jenis akun) — netting dulu, BUKAN buang baris negatif,
        // agar debit + kredit pada akun+cost center yang sama tidak dihitung berlebih (konsisten dgn realisasi GL).
        $addCc  = []; // "account|cc" → delta netto (untuk anggaran per cost center)
        $addAcc = []; // account_id → delta netto lintas cost center (untuk anggaran "Semua" / cost_center NULL)
        foreach ($lines as $l) {
            $accountId = (int) ($l['account_id'] ?? 0);
            if ($accountId <= 0) {
                continue;
            }
            $delta = $this->realisasi->contribution($accountId, (float) ($l['debit'] ?? 0), (float) ($l['credit'] ?? 0));
            $addAcc[$accountId] = ($addAcc[$accountId] ?? 0.0) + $delta;
            $cc = (int) (($l['cost_center_id'] ?? null) ?: $headerCostCenterId ?: 0);
            if ($cc > 0) {
                $addCc[$accountId.'|'.$cc] = ($addCc[$accountId.'|'.$cc] ?? 0.0) + $delta;
            }
        }

        // 1) Anggaran per (akun + cost center) spesifik.
        foreach ($addCc as $key => $delta) {
            if (round($delta, 2) <= 0) {
                continue; // netto tidak menambah realisasi → tak mungkin over-budget
            }
            [$accountId, $cc] = array_map('intval', explode('|', $key));
            $budget = BsBudget::where('year', $year)->where('account_id', $accountId)
                ->where('cost_center_id', $cc)->where('status', 'approved')->first();
            if ($budget) {
                $this->assertCap($budget, $year, $accountId, $cc, $delta, $excludeJournalId);
            }
        }

        // 2) Anggaran "Semua cost center" (cost_center_id NULL) — realisasi & tambahan diagregasi lintas cost center.
        foreach ($addAcc as $accountId => $delta) {
            if (round($delta, 2) <= 0) {
                continue;
            }
            $budget = BsBudget::where('year', $year)->where('account_id', $accountId)
                ->whereNull('cost_center_id')->where('status', 'approved')->first();
            if ($budget) {
                $this->assertCap($budget, $year, (int) $accountId, null, $delta, $excludeJournalId);
            }
        }
    }

    /** Lempar OVER_BUDGET bila realisasi + tambahan melewati plafon. $cc null = anggaran lintas cost center ("Semua"). */
    private function assertCap(BsBudget $budget, int $year, int $accountId, ?int $cc, float $delta, ?int $excludeJournalId): void
    {
        $cap  = round((float) $budget->amount, 2);
        $used = $this->realisasi->total($year, $accountId, $cc, excludeJournalId: $excludeJournalId);
        if (round($used + $delta, 2) > $cap + 0.001) {
            $acc   = BsAccount::find($accountId);
            $rp    = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
            $scope = $cc === null ? ' (semua cost center)' : '';
            throw new BusinessRuleException(
                'Melebihi anggaran '.$year.$scope.' untuk akun '.$acc?->code.' '.$acc?->name.': anggaran '.$rp($cap)
                .', realisasi '.$rp($used).', tambahan '.$rp($delta).' → sisa hanya '.$rp(max(0, $cap - $used))
                .'. Input ditolak.',
                errorCode: 'OVER_BUDGET',
            );
        }
    }
}

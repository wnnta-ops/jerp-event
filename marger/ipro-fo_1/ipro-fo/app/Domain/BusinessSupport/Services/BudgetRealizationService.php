<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsJournalLine;
use Illuminate\Support\Facades\DB;

/**
 * Realisasi anggaran = aktual dari jurnal posted (debit - credit) per tahun/akun/cost center.
 * Dipakai Budget Control (index, export, query Realisasi).
 */
final class BudgetRealizationService
{
    public function total(int $year, int $accountId, ?int $costCenterId = null): float
    {
        return (float) $this->base($year, $accountId, $costCenterId)
            ->sum(DB::raw('bs_journal_lines.debit - bs_journal_lines.credit'));
    }

    /** @return array<int,float> realisasi per bulan (1..12). */
    public function byMonth(int $year, int $accountId, ?int $costCenterId = null): array
    {
        $rows = $this->base($year, $accountId, $costCenterId)
            ->selectRaw('bs_journals.period_month as m, SUM(bs_journal_lines.debit - bs_journal_lines.credit) as v')
            ->groupBy('bs_journals.period_month')->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->m] = (float) $r->v;
        }

        return $out;
    }

    private function base(int $year, int $accountId, ?int $costCenterId)
    {
        return BsJournalLine::query()
            ->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
            ->where('bs_journals.status', 'posted')
            ->where('bs_journals.period_year', $year)
            ->where('bs_journal_lines.account_id', $accountId)
            ->when($costCenterId, fn ($q) => $q->where('bs_journals.cost_center_id', $costCenterId));
    }
}

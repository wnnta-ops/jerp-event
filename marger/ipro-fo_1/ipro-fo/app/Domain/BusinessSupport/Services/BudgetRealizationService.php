<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsJournalLine;
use Illuminate\Support\Facades\DB;

/**
 * Realisasi anggaran = aktual dari jurnal POSTED per tahun/akun/cost center, dengan TANDA mengikuti
 * posisi normal akun (link ke General Ledger):
 *  - COA Aset & Beban (debit-normal)   → Debit menambah, Kredit mengurangi realisasi  (debit − credit)
 *  - COA Kewajiban, Modal & Pendapatan → Debit mengurangi, Kredit menambah realisasi  (credit − debit)
 * Dipakai Budget Control (index, export, query Realisasi) & gerbang over-budget.
 */
final class BudgetRealizationService
{
    /** Cache tanda (+1/−1) per akun agar tidak query berulang saat loop banyak budget. */
    private array $signCache = [];

    public function total(int $year, int $accountId, ?int $costCenterId = null, ?string $from = null, ?string $to = null): float
    {
        $raw = (float) $this->base($year, $accountId, $costCenterId, $from, $to)
            ->sum(DB::raw('bs_journal_lines.debit - bs_journal_lines.credit'));

        return round($this->sign($accountId) * $raw, 2);
    }

    /** @return array<int,float> realisasi per bulan (1..12), sudah bertanda sesuai jenis akun. */
    public function byMonth(int $year, int $accountId, ?int $costCenterId = null, ?string $from = null, ?string $to = null): array
    {
        $sign = $this->sign($accountId);
        $rows = $this->base($year, $accountId, $costCenterId, $from, $to)
            ->selectRaw('bs_journals.period_month as m, SUM(bs_journal_lines.debit - bs_journal_lines.credit) as v')
            ->groupBy('bs_journals.period_month')->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->m] = round($sign * (float) $r->v, 2);
        }

        return $out;
    }

    private function base(int $year, int $accountId, ?int $costCenterId, ?string $from = null, ?string $to = null)
    {
        return BsJournalLine::query()
            ->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
            ->where('bs_journals.status', 'posted')
            ->where('bs_journals.period_year', $year)
            ->where('bs_journal_lines.account_id', $accountId)
            ->when($costCenterId, fn ($q) => $q->where('bs_journals.cost_center_id', $costCenterId))
            // Filter tanggal opsional (Realisasi Budget per periode): batasi tanggal jurnal.
            ->when($from, fn ($q, $d) => $q->whereDate('bs_journals.journal_date', '>=', $d))
            ->when($to, fn ($q, $d) => $q->whereDate('bs_journals.journal_date', '<=', $d));
    }

    /** +1 utk akun debit-normal (Aset/Beban), −1 utk akun credit-normal (Kewajiban/Modal/Pendapatan). */
    private function sign(int $accountId): int
    {
        if (! array_key_exists($accountId, $this->signCache)) {
            $type = BsAccount::whereKey($accountId)->value('type');
            $this->signCache[$accountId] = in_array($type, ['liability', 'equity', 'income'], true) ? -1 : 1;
        }

        return $this->signCache[$accountId];
    }
}

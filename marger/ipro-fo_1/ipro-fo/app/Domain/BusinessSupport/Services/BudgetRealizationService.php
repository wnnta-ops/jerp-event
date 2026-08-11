<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsJournalLine;
use Illuminate\Support\Facades\DB;

/**
 * Realisasi anggaran = aktual dari jurnal (status Baru/Final/Approved) per tahun/akun/cost center,
 * dengan TANDA mengikuti posisi normal akun (link ke General Ledger):
 *  - COA Aset & Beban (debit-normal)   → Debit menambah, Kredit mengurangi realisasi  (debit − credit)
 *  - COA Kewajiban, Modal & Pendapatan → Debit mengurangi, Kredit menambah realisasi  (credit − debit)
 * Dipakai Budget Control (index, export, query Realisasi) & gerbang over-budget.
 */
final class BudgetRealizationService
{
    /** Cache tanda (+1/−1) per akun agar tidak query berulang saat loop banyak budget. */
    private array $signCache = [];

    /** @var array<int,bool> account_id → boleh tanpa cost center */
    private array $optionalCache = [];

    public function total(int $year, int $accountId, ?int $costCenterId = null, ?string $from = null, ?string $to = null, ?int $excludeJournalId = null): float
    {
        $raw = (float) $this->base($year, $accountId, $costCenterId, $from, $to, $excludeJournalId)
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

    private function base(int $year, int $accountId, ?int $costCenterId, ?string $from = null, ?string $to = null, ?int $excludeJournalId = null)
    {
        return BsJournalLine::query()
            ->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
            // Realisasi mengurangi budget sejak status Baru (draft) & Final, bukan hanya Approved (posted).
            ->whereIn('bs_journals.status', ['draft', 'final', 'posted'])
            ->where('bs_journals.period_year', $year)
            // Untuk gerbang over-budget saat EDIT jurnal: kecualikan jurnal itu sendiri agar tak dihitung ganda.
            ->when($excludeJournalId, fn ($q, $id) => $q->where('bs_journals.id', '!=', $id))
            ->where('bs_journal_lines.account_id', $accountId)
            // Cost center efektif = baris (mis. AP set per-baris) → fallback header (jurnal memorial
            // set di header). KECUALI akun yang boleh tanpa cost center: barisnya hanya dihitung bila
            // cost center-nya memang diisi, supaya nilai yang sengaja dikosongkan tidak terhitung
            // sebagai realisasi cost center header.
            ->when($costCenterId, fn ($q) => $this->costCenterOptional($accountId)
                ? $q->where('bs_journal_lines.cost_center_id', $costCenterId)
                : $q->whereRaw('COALESCE(bs_journal_lines.cost_center_id, bs_journals.cost_center_id) = ?', [$costCenterId]))
            // Filter tanggal opsional (Realisasi Budget per periode): batasi tanggal jurnal.
            ->when($from, fn ($q, $d) => $q->whereDate('bs_journals.journal_date', '>=', $d))
            ->when($to, fn ($q, $d) => $q->whereDate('bs_journals.journal_date', '<=', $d));
    }

    /** Kontribusi satu baris ke realisasi budget akunnya (bertanda): +menambah, −mengurangi. Utk gerbang over-budget. */
    public function contribution(int $accountId, float $debit, float $credit): float
    {
        return round($this->sign($accountId) * ($debit - $credit), 2);
    }

    /** Akun ini boleh dijurnal tanpa cost center? (lihat CoaTanpaCostCenterSeeder) */
    private function costCenterOptional(int $accountId): bool
    {
        if (! array_key_exists($accountId, $this->optionalCache)) {
            $this->optionalCache[$accountId] = (bool) BsAccount::whereKey($accountId)->value('cost_center_optional');
        }

        return $this->optionalCache[$accountId];
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

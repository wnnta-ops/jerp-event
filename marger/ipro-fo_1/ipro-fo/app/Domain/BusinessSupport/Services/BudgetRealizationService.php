<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsJournalLine;
use Illuminate\Support\Facades\DB;

/**
 * Realisasi anggaran = aktual dari jurnal (status Baru/Final/Approved) per tahun/akun/cost center,
 * dihitung MURNI dari sisi pengeluaran — hanya sisi normal akun yang dijumlahkan:
 *  - COA Aset & Beban (debit-normal)   → hanya Debit
 *  - COA Kewajiban, Modal & Pendapatan → hanya Kredit
 * Sisi lawannya (mis. jurnal kredit pembatalan/retur pada akun beban) TIDAK mengurangi realisasi —
 * keputusan meeting 3 Nov 2026: realisasi = pengeluaran, sisa = anggaran bulan − realisasi bulan.
 * Dipakai Budget Control (index, export, query Realisasi) & gerbang over-budget.
 */
final class BudgetRealizationService
{
    /** Status jurnal yang dihitung sebagai realisasi — dipakai base() maupun perCostCenter(). */
    private const STATUS_DIHITUNG = ['draft', 'final', 'posted'];

    /** Jenis akun credit-normal: debit MENGURANGI realisasi. */
    private const KREDIT_NORMAL = ['liability', 'equity', 'income'];

    /** Cache tanda (+1/−1) per akun agar tidak query berulang saat loop banyak budget. */
    private array $signCache = [];

    /** @var array<int,bool> account_id → boleh tanpa cost center */
    private array $optionalCache = [];

    public function total(int $year, int $accountId, ?int $costCenterId = null, ?string $from = null, ?string $to = null, ?int $excludeJournalId = null): float
    {
        return round((float) $this->base($year, $accountId, $costCenterId, $from, $to, $excludeJournalId)
            ->sum(DB::raw($this->kolom($accountId))), 2);
    }

    /** @return array<int,float> realisasi per bulan (1..12), sudah bertanda sesuai jenis akun. */
    public function byMonth(int $year, int $accountId, ?int $costCenterId = null, ?string $from = null, ?string $to = null): array
    {
        $rows = $this->base($year, $accountId, $costCenterId, $from, $to)
            ->selectRaw('bs_journals.period_month as m, SUM('.$this->kolom($accountId).') as v')
            ->groupBy('bs_journals.period_month')->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->m] = round((float) $r->v, 2);
        }

        return $out;
    }

    private function base(int $year, int $accountId, ?int $costCenterId, ?string $from = null, ?string $to = null, ?int $excludeJournalId = null)
    {
        return BsJournalLine::query()
            ->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
            // Realisasi mengurangi budget sejak status Baru (draft) & Final, bukan hanya Approved (posted).
            ->whereIn('bs_journals.status', self::STATUS_DIHITUNG)
            ->where('bs_journals.period_year', $year)
            // Join mentah ke bs_journals melewati global scope tenant milik model, jadi tenant-nya
            // disaring di sini. Pola "hanya bila tenant.id terikat" mengikuti TenantScope: di luar
            // konteks request (job/command) penyaringan dilepas agar tidak nihil.
            ->when(tenant_id(), fn ($q, $t) => $q->where('bs_journals.tenant_id', $t))
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

    /**
     * Realisasi SELURUH pasangan (cost center efektif × akun) sekaligus, dalam SATU query.
     *
     * Aturannya sengaja sama persis dengan total(): status jurnal, tahun, tenant, rentang tanggal,
     * cost center efektif (baris → fallback header, KECUALI akun cost_center_optional yang hanya
     * menghitung cost center baris), dan tanda menurut jenis akun. Bedanya hanya satu: dikerjakan
     * sekali untuk semua pasangan, karena Report Budget memuat puluhan baris dan memanggil total()
     * per baris berarti puluhan query.
     *
     * @return array<string,float> kunci "costCenterId|accountId" — costCenterId 0 = tanpa cost center
     */
    public function perCostCenter(int $year, ?string $from = null, ?string $to = null): array
    {
        $ccEfektif = 'CASE WHEN bs_accounts.cost_center_optional = 1 THEN bs_journal_lines.cost_center_id'
            .' ELSE COALESCE(bs_journal_lines.cost_center_id, bs_journals.cost_center_id) END';
        $bertanda  = 'CASE WHEN bs_accounts.type IN ('
            .rtrim(str_repeat('?,', count(self::KREDIT_NORMAL)), ',').') THEN bs_journal_lines.credit ELSE bs_journal_lines.debit END';

        $rows = BsJournalLine::query()
            ->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
            ->join('bs_accounts', 'bs_accounts.id', '=', 'bs_journal_lines.account_id')
            ->whereIn('bs_journals.status', self::STATUS_DIHITUNG)
            ->where('bs_journals.period_year', $year)
            ->when(tenant_id(), fn ($q, $t) => $q->where('bs_journals.tenant_id', $t))
            ->when($from, fn ($q, $d) => $q->whereDate('bs_journals.journal_date', '>=', $d))
            ->when($to, fn ($q, $d) => $q->whereDate('bs_journals.journal_date', '<=', $d))
            ->selectRaw($ccEfektif.' as cc, bs_journal_lines.account_id as acc, SUM('.$bertanda.') as v', self::KREDIT_NORMAL)
            ->groupBy('cc', 'acc')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[((int) $r->cc).'|'.((int) $r->acc)] = round((float) $r->v, 2);
        }

        return $out;
    }

    /**
     * Baris jurnal di balik satu angka realisasi (drill-down Budget Control). Aturannya sama dengan
     * total(); $costCenterId null = lintas cost center, 0 = baris yang cost center efektifnya kosong.
     *
     * @return list<array{journal_id:int,nomor:string,tanggal:string,keterangan:string,cost_center_id:int,nilai:float}>
     */
    public function rincian(int $year, int $accountId, ?int $costCenterId, ?string $from = null, ?string $to = null): array
    {
        $ccEfektif = $this->costCenterOptional($accountId)
            ? 'bs_journal_lines.cost_center_id'
            : 'COALESCE(bs_journal_lines.cost_center_id, bs_journals.cost_center_id)';
        $kolom = $this->kolom($accountId);

        $q = $costCenterId === 0
            ? $this->base($year, $accountId, null, $from, $to)->whereRaw($ccEfektif.' IS NULL')
            : $this->base($year, $accountId, $costCenterId, $from, $to);

        return $q->where($kolom, '>', 0)->orderBy('bs_journals.journal_date')->orderBy('bs_journals.id')
            ->selectRaw('bs_journals.id as jid, bs_journals.journal_number as nomor, bs_journals.journal_date as tgl,'
                .' COALESCE(bs_journal_lines.description, bs_journals.description) as ket, '.$ccEfektif.' as cc,'
                .' '.$kolom.' as v')
            ->get()
            ->map(fn ($r) => [
                'journal_id' => (int) $r->jid, 'nomor' => (string) $r->nomor,
                'tanggal' => substr((string) $r->tgl, 0, 10), 'keterangan' => (string) $r->ket,
                'cost_center_id' => (int) $r->cc, 'nilai' => round((float) $r->v, 2),
            ])->all();
    }

    /** Kontribusi satu baris ke realisasi budget akunnya: hanya sisi normal akun (lihat docblock kelas). Utk gerbang over-budget. */
    public function contribution(int $accountId, float $debit, float $credit): float
    {
        return round($this->sign($accountId) > 0 ? $debit : $credit, 2);
    }

    /** Kolom yang dijumlahkan sebagai realisasi akun ini: debit (debit-normal) atau credit (credit-normal). */
    private function kolom(int $accountId): string
    {
        return $this->sign($accountId) > 0 ? 'bs_journal_lines.debit' : 'bs_journal_lines.credit';
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
            $this->signCache[$accountId] = in_array($type, self::KREDIT_NORMAL, true) ? -1 : 1;
        }

        return $this->signCache[$accountId];
    }
}

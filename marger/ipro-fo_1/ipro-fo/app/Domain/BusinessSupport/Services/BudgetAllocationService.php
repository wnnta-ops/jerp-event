<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsBudget;
use App\Domain\BusinessSupport\Models\BsBudgetAllocation;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Carbon;

/**
 * Alokasi Budget = memindah anggaran dari budget A (COA lama) ke budget B (COA baru) per bulan.
 *
 * Dari layar Alokasi Budget: langsung berlaku tanpa approval (keputusan meeting 3 Nov 2026) —
 * bulan-bulan budget A berkurang, bulan yang sama di budget B bertambah, anggaran tahunan keduanya
 * ikut. Dari langkah Struktur Registrasi Invoice Hutang: WAJIB approval — tersimpan 'baru' dan baru
 * dipindah saat disetujui di Approval Alokasi Budget (ditolak → tidak dipindah).
 *
 * Yang boleh dipindah dari satu bulan paling banyak SISA bulan itu di budget A (anggaran bulan −
 * realisasi bulan − alokasi keluar yang masih menunggu) — anggaran yang sudah terserap atau sudah
 * dipesan alokasi lain tidak bisa dipindah. Dicek lagi saat disetujui.
 */
final class BudgetAllocationService
{
    public function __construct(private readonly BudgetRealizationService $realisasi) {}

    /**
     * Sisa satu bulan budget = anggaran bulan − realisasi bulan ("Semua cost center" = realisasi
     * seluruh cost center akunnya) − alokasi keluar bulan itu yang masih menunggu approval.
     */
    public function sisaBulan(BsBudget $b, int $bulan, ?int $excludeJournalId = null, ?int $kecualiAlokasiId = null): float
    {
        $tgl = Carbon::create((int) $b->year, $bulan, 1);
        $real = $this->realisasi->total((int) $b->year, (int) $b->account_id, $b->cost_center_id ? (int) $b->cost_center_id : null,
            $tgl->toDateString(), $tgl->copy()->endOfMonth()->toDateString(), $excludeJournalId);
        $dipesan = BsBudgetAllocation::query()->where('budget_id', $b->id)->where('status', 'baru')
            ->when($kecualiAlokasiId, fn ($q, $id) => $q->where('id', '!=', $id))->get(['months'])
            ->sum(fn ($a) => (float) ($a->months[$bulan] ?? $a->months[(string) $bulan] ?? 0));

        return round(BudgetGuardService::anggaranBulan($b, $bulan) - $real - $dipesan, 2);
    }

    /**
     * Pindahkan anggaran. Dipanggil DI DALAM transaksi.
     *
     * @param  array<int,float>  $months  bulan (1..12) → nilai yang dipindah (> 0)
     * @param  array{type:string,id:int}|null  $sumber  dokumen asal (mis. invoice hutang)
     * @param  bool  $langsung  false = simpan 'baru' (menunggu Approval Alokasi Budget), anggaran belum dipindah
     *
     * @throws BusinessRuleException
     */
    public function pindah(int $asalId, int $tujuanId, array $months, string $tanggal, ?string $note, int $userId, ?array $sumber = null, ?int $excludeJournalId = null, bool $langsung = true): BsBudgetAllocation
    {
        [$asal, $tujuan, $pindah] = $this->periksa($asalId, $tujuanId, $months, $excludeJournalId);
        $total = round(array_sum($pindah), 2);

        if ($langsung) {
            $this->terapkan($asal, $tujuan, $pindah);
        }

        return BsBudgetAllocation::create([
            'tenant_id' => $asal->tenant_id,
            'doc_no' => BsBudgetAllocation::nextDocNo((int) $asal->year, $asal->project, $tanggal),
            'year' => $asal->year, 'project' => $asal->project,
            'budget_id' => $asal->id, 'to_budget_id' => $tujuan->id,
            'cost_center_id' => $asal->cost_center_id, 'account_id' => $asal->account_id,
            'alloc_date' => $tanggal, 'period_from' => (int) array_key_first($pindah), 'period_to' => (int) array_key_last($pindah),
            'amount' => $total, 'months' => $pindah, 'total_budget' => (float) $asal->amount,
            'status' => $langsung ? 'approved' : 'baru',
            'approved_at' => $langsung ? now() : null, 'approved_by' => $langsung ? $userId : null,
            'notes' => $note !== null ? mb_substr($note, 0, 200) : null, 'created_by' => $userId,
            'source_type' => $sumber['type'] ?? null, 'source_id' => $sumber['id'] ?? null,
        ]);
    }

    /**
     * Putuskan alokasi yang menunggu (dari invoice hutang). Disetujui → sisa budget asal dicek lagi
     * lalu anggaran dipindah; ditolak → hanya ditandai. Dipanggil DI DALAM transaksi.
     *
     * @throws BusinessRuleException
     */
    public function putuskan(BsBudgetAllocation $a, bool $setuju, int $userId): void
    {
        if ($a->status !== 'baru') {
            return;
        }
        if ($setuju) {
            [$asal, $tujuan, $pindah] = $this->periksa((int) $a->budget_id, (int) $a->to_budget_id,
                collect($a->months ?? [])->mapWithKeys(fn ($v, $m) => [(int) $m => (float) $v])->all(), null, $a->id);
            $this->terapkan($asal, $tujuan, $pindah);
        }
        $a->update(['status' => $setuju ? 'approved' : 'rejected', 'approved_at' => now(), 'approved_by' => $userId]);
    }

    /** @param array<int,float> $pindah */
    private function terapkan(BsBudget $asal, BsBudget $tujuan, array $pindah): void
    {
        $asal->tambahBulan(array_map(fn ($v) => -$v, $pindah));
        $asal->save();
        $tujuan->tambahBulan($pindah);
        $tujuan->save();
    }

    /**
     * Kunci & validasi budget asal/tujuan dan nilai per bulan.
     *
     * @return array{0:BsBudget, 1:BsBudget, 2:array<int,float>}
     *
     * @throws BusinessRuleException
     */
    private function periksa(int $asalId, int $tujuanId, array $months, ?int $excludeJournalId, ?int $kecualiAlokasiId = null): array
    {
        if ($asalId === $tujuanId) {
            throw new BusinessRuleException('Budget asal dan tujuan tidak boleh sama.', errorCode: 'ALLOC_SAME');
        }
        // Kunci dua baris dengan urutan id tetap supaya dua alokasi bersilangan tidak saling menunggu.
        $kunci = BsBudget::query()->whereIn('id', [$asalId, $tujuanId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $asal = $kunci->get($asalId);
        $tujuan = $kunci->get($tujuanId);
        if (! $asal || ! $tujuan) {
            throw new BusinessRuleException('Budget asal / tujuan tidak ditemukan.', errorCode: 'ALLOC_NOT_FOUND');
        }
        if ($asal->status !== 'approved' || $tujuan->status !== 'approved') {
            throw new BusinessRuleException('Alokasi hanya antar anggaran yang sudah disetujui.', errorCode: 'ALLOC_STATUS');
        }
        if ((int) $asal->year !== (int) $tujuan->year) {
            throw new BusinessRuleException('Budget asal dan tujuan harus di tahun yang sama.', errorCode: 'ALLOC_YEAR');
        }

        $pindah = [];
        foreach ($months as $m => $v) {
            $m = (int) $m;
            $v = round((float) $v, 2);
            if ($m < 1 || $m > 12 || $v <= 0) {
                continue;
            }
            $sisa = $this->sisaBulan($asal, $m, $excludeJournalId, $kecualiAlokasiId);
            if ($v > $sisa + 0.001) {
                throw new BusinessRuleException('Alokasi '.BsBudget::MONTHS[$m - 1].' '.$asal->year.' melebihi sisa budget asal '
                    .$asal->doc_no.' (sisa Rp '.number_format(max(0, $sisa), 0, ',', '.').').', errorCode: 'ALLOC_OVER');
            }
            $pindah[$m] = $v;
        }
        if ($pindah === []) {
            throw new BusinessRuleException('Isi nilai yang dipindah minimal di satu bulan.', errorCode: 'ALLOC_EMPTY');
        }
        ksort($pindah);

        return [$asal, $tujuan, $pindah];
    }
}

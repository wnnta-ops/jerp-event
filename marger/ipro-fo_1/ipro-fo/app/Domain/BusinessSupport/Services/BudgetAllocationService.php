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
 * Langsung berlaku tanpa approval (keputusan meeting 3 Nov 2026): bulan-bulan budget A berkurang,
 * bulan yang sama di budget B bertambah, anggaran tahunan keduanya ikut. Yang boleh dipindah dari
 * satu bulan paling banyak SISA bulan itu di budget A (anggaran bulan − realisasi bulan, aturan
 * penjaga budget) — anggaran yang sudah terserap tidak bisa dipindah.
 *
 * Dipakai layar Alokasi Budget dan langkah Struktur Registrasi Invoice Hutang.
 */
final class BudgetAllocationService
{
    public function __construct(private readonly BudgetRealizationService $realisasi) {}

    /**
     * Sisa satu bulan budget = anggaran bulan − realisasi bulan ("Semua cost center" = realisasi
     * seluruh cost center akunnya).
     */
    public function sisaBulan(BsBudget $b, int $bulan, ?int $excludeJournalId = null): float
    {
        $tgl = Carbon::create((int) $b->year, $bulan, 1);
        $real = $this->realisasi->total((int) $b->year, (int) $b->account_id, $b->cost_center_id ? (int) $b->cost_center_id : null,
            $tgl->toDateString(), $tgl->copy()->endOfMonth()->toDateString(), $excludeJournalId);

        return round(BudgetGuardService::anggaranBulan($b, $bulan) - $real, 2);
    }

    /**
     * Pindahkan anggaran. Dipanggil DI DALAM transaksi.
     *
     * @param  array<int,float>  $months  bulan (1..12) → nilai yang dipindah (> 0)
     * @param  array{type:string,id:int}|null  $sumber  dokumen asal (mis. invoice hutang)
     *
     * @throws BusinessRuleException
     */
    public function pindah(int $asalId, int $tujuanId, array $months, string $tanggal, ?string $note, int $userId, ?array $sumber = null, ?int $excludeJournalId = null): BsBudgetAllocation
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
            $sisa = $this->sisaBulan($asal, $m, $excludeJournalId);
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
        $total = round(array_sum($pindah), 2);

        $asal->tambahBulan(array_map(fn ($v) => -$v, $pindah));
        $asal->save();
        $tujuan->tambahBulan($pindah);
        $tujuan->save();

        return BsBudgetAllocation::create([
            'tenant_id' => $asal->tenant_id,
            'doc_no' => BsBudgetAllocation::nextDocNo((int) $asal->year, $asal->project, $tanggal),
            'year' => $asal->year, 'project' => $asal->project,
            'budget_id' => $asal->id, 'to_budget_id' => $tujuan->id,
            'cost_center_id' => $asal->cost_center_id, 'account_id' => $asal->account_id,
            'alloc_date' => $tanggal, 'period_from' => (int) array_key_first($pindah), 'period_to' => (int) array_key_last($pindah),
            'amount' => $total, 'months' => $pindah, 'total_budget' => (float) $asal->amount,
            'status' => 'approved', 'approved_at' => now(), 'approved_by' => $userId,
            'notes' => $note !== null ? mb_substr($note, 0, 200) : null, 'created_by' => $userId,
            'source_type' => $sumber['type'] ?? null, 'source_id' => $sumber['id'] ?? null,
        ]);
    }
}

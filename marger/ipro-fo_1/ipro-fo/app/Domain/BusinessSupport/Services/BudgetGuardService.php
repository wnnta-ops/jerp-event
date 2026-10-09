<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsBudget;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Carbon;

/**
 * Gerbang anggaran: mencegah input yang melewati Anggaran (approved) per (akun + cost center +
 * tahun), DAN menolak input pada akun pembawa biaya yang belum punya anggaran sama sekali.
 * Cost center efektif per baris = baris → fallback header jurnal.
 *
 * Dipakai alur AP (Registrasi Invoice Hutang, Penghapusan Nilai Hutang, Posting Invoice),
 * Jurnal Umum, dan Bon Sementara.
 *
 * ── DUA PENOLAKAN YANG BERBEDA ──
 *
 *   OVER_BUDGET       — anggarannya ada, tetapi tidak cukup.
 *   BUDGET_TIDAK_ADA  — belum ada baris anggaran approved untuk akun+cost center itu.
 *
 * Dibedakan karena tindakannya berbeda: yang pertama minta tambahan anggaran, yang kedua minta
 * anggarannya DIBUAT. Pesan yang menggabungkan keduanya membuat orang mencari-cari baris yang
 * tidak pernah ada.
 *
 * ── KENAPA HANYA AKUN PEMBAWA BIAYA ──
 *
 * Yang wajib beranggaran hanya akun yang MENUNTUT cost center, yaitu yang tidak bertanda
 * `cost_center_optional` di COA. Tanpa batas itu, tidak ada satu pun dokumen yang bisa
 * disimpan: Utang Usaha, Kas, dan PPN tidak pernah dianggarkan, dan memaksa anggaran untuk
 * ketiganya akan mematikan seluruh modul.
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

        // 3) BELUM ADA ANGGARAN sama sekali untuk akun pembawa biaya → tolak.
        $this->assertAnggaranAda($addCc, $year);
    }

    /**
     * Tolak bila akun pembawa biaya belum punya anggaran approved — baik per cost center-nya
     * sendiri maupun anggaran "Semua cost center".
     *
     * Keduanya diterima sebagai "ada": anggaran lintas cost center memang sah menaungi semua.
     *
     * @param  array<string, float>  $addCc  "account|cc" → delta netto
     *
     * @throws BusinessRuleException
     */
    private function assertAnggaranAda(array $addCc, int $year): void
    {
        // Sakelar: aturan ini masih menunggu kepastian Accounting. Dimatikan → kembali ke
        // perilaku lama (yang TIDAK ADA diloloskan; yang KURANG tetap ditolak).
        if (! config('business_support.budget.wajib_ada', true)) {
            return;
        }

        $positif = array_filter($addCc, fn ($d) => round($d, 2) > 0);
        if ($positif === []) {
            return;
        }

        // Akun diambil SEKALI, bukan sekali per baris: satu dokumen bisa punya belasan baris
        // jurnal dan sebagian besar menunjuk akun yang sama.
        $accountId = [];
        foreach (array_keys($positif) as $key) {
            $accountId[] = (int) explode('|', $key)[0];
        }

        $akun = BsAccount::whereIn('id', array_unique($accountId))
            ->get(['id', 'code', 'name', 'cost_center_optional'])->keyBy('id');

        $bebas = array_map('strval', (array) config('business_support.budget.coa_tanpa_anggaran', []));

        foreach ($positif as $key => $delta) {
            [$accId, $cc] = array_map('intval', explode('|', $key));
            $acc = $akun->get($accId);

            // DUA SUMBER PENGECUALIAN, dan keduanya dipakai:
            //
            //   1. Daftar resmi Accounting (config budget.coa_tanpa_anggaran) — otoritatif.
            //   2. Penanda cost_center_optional di COA — jaring pengaman.
            //
            // Keduanya, bukan salah satu: daftar resminya belum memuat beberapa akun yang
            // dipakai JURNAL OTOMATIS dan tidak pernah dianggarkan siapa pun — mis. PPN
            // Keluaran (20104040406) dan Biaya Umum & Administrasi Lain-lain (50302020228,
            // debit bawaan PPh ditanggung). Memakai daftar resmi saja akan menolak setiap
            // invoice ber-PPN, dan sebabnya tidak akan terbaca sebagai kesalahan daftar.
            if ($acc === null || $acc->cost_center_optional || in_array($acc->code, $bebas, true)) {
                continue;
            }

            $ada = BsBudget::where('year', $year)->where('account_id', $accId)
                ->where('status', 'approved')
                ->where(fn ($q) => $q->where('cost_center_id', $cc)->orWhereNull('cost_center_id'))
                ->exists();

            if ($ada) {
                continue;
            }

            $namaCc = BsCostCenter::find($cc);
            throw new BusinessRuleException(
                'Belum ada anggaran '.$year.' untuk akun '.$acc->code.' '.$acc->name
                .' pada cost center '.($namaCc?->code ?? $cc).' '.($namaCc?->name ?? '')
                .'. Buat dulu anggarannya di Budget Control — Master Budget, lalu setujui. Input ditolak.',
                errorCode: 'BUDGET_TIDAK_ADA',
            );
        }
    }

    /**
     * Gerbang anggaran tingkat DEPARTEMEN — dipakai Bon Sementara.
     *
     * Berbeda dari assertWithinBudget() yang memeriksa per (akun + cost center): saat bon
     * DIAJUKAN, akun bebannya BELUM diketahui — baru ditentukan nanti pada realisasi. Yang
     * bisa diperiksa hanya plafon departemennya: jumlah seluruh anggaran approved cost center
     * itu pada tahun berjalan, dikurangi realisasinya.
     *
     * ASUMSI YANG PERLU DIKETAHUI: "budget dept" ditafsirkan sebagai JUMLAH seluruh anggaran
     * cost center tersebut, bukan satu akun tertentu. Itu pembacaan yang masuk akal untuk
     * permintaan "bon sementara tidak boleh melebihi budget dept", tetapi ia memperbolehkan
     * bon memakai sisa anggaran akun lain — pembatasan per akun baru terjadi saat realisasi,
     * lewat assertWithinBudget().
     *
     * @throws BusinessRuleException
     */
    public function assertPlafonCostCenter(int $costCenterId, float $jumlah, string $tanggal): void
    {
        if (! config('business_support.budget.wajib_ada', true)) {
            return;
        }

        $year = (int) Carbon::parse($tanggal)->year;

        $budget = BsBudget::where('year', $year)->where('status', 'approved')
            ->where(fn ($q) => $q->where('cost_center_id', $costCenterId)->orWhereNull('cost_center_id'))
            ->get(['account_id', 'cost_center_id', 'amount']);

        $cc = BsCostCenter::find($costCenterId);
        $sebut = ($cc?->code ?? $costCenterId).' '.($cc?->name ?? '');

        if ($budget->isEmpty()) {
            throw new BusinessRuleException(
                'Belum ada anggaran '.$year.' untuk cost center '.$sebut
                .'. Buat dulu anggarannya di Budget Control — Master Budget, lalu setujui. Pengajuan ditolak.',
                errorCode: 'BUDGET_TIDAK_ADA',
            );
        }

        $plafon = round((float) $budget->sum('amount'), 2);
        $terpakai = 0.0;
        foreach ($budget as $b) {
            $terpakai += $this->realisasi->total($year, (int) $b->account_id, $b->cost_center_id !== null ? $costCenterId : null);
        }
        $terpakai = round($terpakai, 2);
        $sisa = round($plafon - $terpakai, 2);

        if (round($jumlah, 2) > $sisa + 0.001) {
            $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
            throw new BusinessRuleException(
                'Melebihi anggaran '.$year.' cost center '.$sebut.': anggaran '.$rp($plafon)
                .', realisasi '.$rp($terpakai).', pengajuan '.$rp($jumlah).' → sisa hanya '.$rp(max(0, $sisa))
                .'. Pengajuan ditolak.',
                errorCode: 'OVER_BUDGET',
            );
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

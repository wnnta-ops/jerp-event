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
     * @param  array<int,float>  $koreksi  budget_id → tambahan yang DIAJUKAN bersama dokumen ini (koreksi
     *   budget dari Registrasi Invoice Hutang) — dihitung sebagai ruang tambahan bulan itu.
     *
     * PENJAGA BULANAN: yang dibandingkan adalah anggaran BULAN tanggal dokumen (kolom m1..m12)
     * dengan realisasi bulan itu saja — bukan anggaran setahun. Sisa bulan lalu tidak terbawa.
     *
     * @throws BusinessRuleException bila ada (akun+cost center) yang melebihi anggaran
     */
    public function assertWithinBudget(array $lines, ?int $headerCostCenterId, string $journalDate, ?int $excludeJournalId = null, array $koreksi = []): void
    {
        $tgl = Carbon::parse($journalDate);
        $year = (int) $tgl->year;

        // Kontribusi per baris = sisi pengeluaran akunnya saja (debit utk akun beban), sama dengan
        // BudgetRealizationService — jurnal kredit di akun beban tidak membuka ruang anggaran.
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
                $this->assertCap($budget, $tgl, $accountId, $cc, $delta, $excludeJournalId, (float) ($koreksi[$budget->id] ?? 0));
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
                $this->assertCap($budget, $tgl, (int) $accountId, null, $delta, $excludeJournalId, (float) ($koreksi[$budget->id] ?? 0));
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

    /**
     * Sisa budget BULAN tanggal dokumen untuk satu akun × cost center — dipakai kolom "Sisa Budget"
     * di Struktur Jurnal. Anggaran per cost center didahulukan; bila tidak ada, anggaran "Semua
     * cost center" (realisasinya lintas cost center). null = akun ini tidak punya anggaran.
     * Ikut juga angka SETAHUN (anggaran berlaku m1..m12 & realisasi tahun itu, sama dengan Master
     * Budget) untuk ringkasan Sisa Budget Tahunan & Bulanan di bawah Struktur Jurnal.
     *
     * @return array{budget_id:int, cakupan:string, anggaran:float, terpakai:float, anggaran_tahun:float, terpakai_tahun:float, label:string, tanda:int}|null
     */
    public function sisaBulan(int $accountId, ?int $costCenterId, string $tanggal, ?int $excludeJournalId = null): ?array
    {
        $tgl = Carbon::parse($tanggal);
        $budget = $costCenterId
            ? BsBudget::where('year', $tgl->year)->where('account_id', $accountId)->where('cost_center_id', $costCenterId)->where('status', 'approved')->first()
            : null;
        $cc = $costCenterId;
        if (! $budget) {
            $budget = BsBudget::where('year', $tgl->year)->where('account_id', $accountId)->whereNull('cost_center_id')->where('status', 'approved')->first();
            $cc = null;
        }
        if (! $budget) {
            return null;
        }

        return [
            'budget_id' => $budget->id,
            'cakupan' => $cc === null ? 'semua' : 'cc',
            'anggaran' => self::anggaranBulan($budget, (int) $tgl->month),
            'terpakai' => $this->terpakaiBulan($tgl, $accountId, $cc, $excludeJournalId),
            'anggaran_tahun' => round(array_sum(array_map(fn ($m) => self::anggaranBulan($budget, $m), range(1, 12))), 2),
            'terpakai_tahun' => $this->realisasi->total((int) $tgl->year, $accountId, $cc, null, null, $excludeJournalId),
            'label' => trim(($budget->loadMissing(['account:id,code,name', 'costCenter:id,code,name'])->account?->code ?? '').' '.$budget->account?->name)
                .' · '.($budget->costCenter?->name ?? 'Semua cost center'),
            // +1: debit yang dihitung sebagai realisasi (akun beban); -1: kredit yang dihitung.
            'tanda' => $this->realisasi->contribution($accountId, 1, 0) > 0 ? 1 : -1,
        ];
    }

    /**
     * Akun ini WAJIB punya anggaran (aturan yang sama dengan assertAnggaranAda): bukan akun
     * cost_center_optional dan tidak ada di daftar COA tanpa anggaran. Kas, Hutang, PPN dsb. tidak.
     */
    public function wajibAnggaran(int $accountId): bool
    {
        if (! config('business_support.budget.wajib_ada', true)) {
            return false;
        }
        $acc = BsAccount::query()->find($accountId, ['id', 'code', 'cost_center_optional']);
        $bebas = array_map('strval', (array) config('business_support.budget.coa_tanpa_anggaran', []));

        return $acc !== null && ! $acc->cost_center_optional && ! in_array($acc->code, $bebas, true);
    }

    /**
     * Langkah Struktur Registrasi Invoice Hutang: akun wajib-anggaran yang BELUM punya anggaran
     * (akun × cost center) dianggap anggaran 0 — kekurangannya wajib ditutup Alokasi / Koreksi.
     * budget_id negatif = penanda "budget nol" (BudgetNol membuat Master Budget-nya saat diajukan).
     */
    public function sisaBulanNol(int $accountId, int $costCenterId, string $tanggal, ?int $excludeJournalId = null): array
    {
        $tgl = Carbon::parse($tanggal);
        $acc = BsAccount::query()->find($accountId, ['id', 'code', 'name']);
        $cc = BsCostCenter::query()->find($costCenterId, ['id', 'code', 'name']);

        return [
            'budget_id' => BudgetNol::kunci($accountId, $costCenterId),
            'baru' => true,
            'cakupan' => 'cc',
            'anggaran' => 0.0,
            'terpakai' => $this->terpakaiBulan($tgl, $accountId, $costCenterId, $excludeJournalId),
            'anggaran_tahun' => 0.0,
            'terpakai_tahun' => $this->realisasi->total((int) $tgl->year, $accountId, $costCenterId, null, null, $excludeJournalId),
            'label' => trim(($acc?->code ?? '').' '.$acc?->name).' · '.($cc?->name ?? '—').' (belum ada budget)',
            'tanda' => $this->realisasi->contribution($accountId, 1, 0) > 0 ? 1 : -1,
        ];
    }

    /**
     * Anggaran satu bulan dari master budget (kolom m1..m12). Budget lama yang belum dirinci per
     * bulan (seluruh kolom bulanan kosong) dibagi rata 12.
     */
    public static function anggaranBulan(BsBudget $budget, int $bulan): float
    {
        $rinci = 0.0;
        for ($m = 1; $m <= 12; $m++) {
            $rinci += abs((float) ($budget->{'m'.$m} ?? 0));
        }

        return round($rinci > 0 ? (float) ($budget->{'m'.$bulan} ?? 0) : (float) $budget->amount / 12, 2);
    }

    /** Realisasi akun × cost center (null = lintas cost center) pada BULAN tanggal tersebut. */
    private function terpakaiBulan(Carbon $tgl, int $accountId, ?int $cc, ?int $excludeJournalId): float
    {
        return $this->realisasi->total((int) $tgl->year, $accountId, $cc,
            $tgl->copy()->startOfMonth()->toDateString(), $tgl->copy()->endOfMonth()->toDateString(), $excludeJournalId);
    }

    /** Lempar OVER_BUDGET bila realisasi BULAN ini + tambahan melewati anggaran bulan ini (+ koreksi yang diajukan). $cc null = anggaran lintas cost center ("Semua"). */
    private function assertCap(BsBudget $budget, Carbon $tgl, int $accountId, ?int $cc, float $delta, ?int $excludeJournalId, float $koreksi = 0.0): void
    {
        $cap = self::anggaranBulan($budget, (int) $tgl->month);
        $used = $this->terpakaiBulan($tgl, $accountId, $cc, $excludeJournalId);
        if (round($used + $delta, 2) > round($cap + $koreksi, 2) + 0.001) {
            $acc = BsAccount::find($accountId);
            $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
            $scope = $cc === null ? ' (semua cost center)' : '';
            $periode = BsBudget::MONTHS[$tgl->month - 1].' '.$tgl->year;
            throw new BusinessRuleException(
                'Melebihi anggaran '.$periode.$scope.' untuk akun '.$acc?->code.' '.$acc?->name.': anggaran bulan ini '.$rp($cap)
                .', realisasi bulan ini '.$rp($used).', tambahan '.$rp($delta).' → sisa hanya '.$rp(max(0, $cap - $used))
                .($koreksi > 0 ? ', koreksi diajukan '.$rp($koreksi).' belum mencukupi' : ' — ajukan Koreksi Budget')
                .'. Input ditolak.',
                errorCode: 'OVER_BUDGET',
            );
        }
    }
}

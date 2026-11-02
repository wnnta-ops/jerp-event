<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsBudget;
use App\Domain\BusinessSupport\Models\BsBudgetCorrection;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use Carbon\Carbon;

/**
 * Anggaran vs Realisasi Tahunan — isi halaman Master Budget (Budget Control).
 *
 * Susunan: Pendapatan / Beban / Lainnya → kelompok akun tingkat 3 → akun → cost center, ditutup
 * LABA (pendapatan − beban). Tiap baris memuat lima angka yang sama:
 *   · Anggaran s.d.   = Σ anggaran bulan Jan..s.d. (m1..mN; budget tanpa rincian bulanan = amount/12
 *                       — persis BudgetGuardService::anggaranBulan, jadi sama dengan penjaga budget)
 *   · Realisasi s.d.  = jurnal Jan..akhir bulan s.d. (BudgetRealizationService, aturan yang sama
 *                       dengan penjaga budget & Koreksi Budget)
 *   · Selisih         = positif berarti lebih baik: pendapatan realisasi − anggaran, beban
 *                       anggaran − realisasi
 *   · %               = realisasi ÷ anggaran s.d.
 *   · Anggaran setahun = Σ anggaran 12 bulan
 *
 * Versi anggaran: "berlaku" = master budget saat ini (sudah termasuk Koreksi Budget yang disetujui);
 * "awal" = master budget dikurangi tambahan dari Koreksi Budget yang disetujui, dibalik dengan rumus
 * yang sama dengan BudgetCorrectionService::putuskan (selisih dibagi rata ke bulan periodenya).
 */
final class BudgetAnnualReportService
{
    public const VERSI = ['berlaku' => 'Berlaku (setelah Koreksi Budget)', 'awal' => 'Awal (sebelum Koreksi Budget)'];

    public const BAGIAN = ['income' => 'Pendapatan', 'expense' => 'Beban', 'lainnya' => 'Lainnya (Aset / Kewajiban / Modal)'];

    public function __construct(private readonly BudgetRealizationService $realisasi) {}

    /** Rentang tanggal realisasi Jan..akhir bulan s.d. */
    public static function rentang(int $year, int $sd): array
    {
        return [sprintf('%04d-01-01', $year), Carbon::create($year, $sd, 1)->endOfMonth()->toDateString()];
    }

    /**
     * Anggaran per bulan (1..12) satu budget pada versi terpilih.
     *
     * @param  array<int,array<int,float>>  $tambahanKoreksi  budget_id → bulan → tambahan dari koreksi disetujui
     * @return array<int,float>
     */
    public static function bulanVersi(BsBudget $b, string $versi, array $tambahanKoreksi): array
    {
        $salin = clone $b;
        if ($versi === 'awal') {
            $total = 0.0;
            foreach ($tambahanKoreksi[$b->id] ?? [] as $m => $v) {
                $salin->{'m'.$m} = round((float) ($salin->{'m'.$m} ?? 0) - $v, 2);
                $total += $v;
            }
            $salin->amount = round((float) $salin->amount - $total, 2);
        }
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $out[$m] = BudgetGuardService::anggaranBulan($salin, $m);
        }

        return $out;
    }

    /**
     * Tambahan per bulan dari Koreksi Budget yang SUDAH disetujui.
     *
     * @param  list<int>  $budgetIds
     * @return array<int,array<int,float>>
     */
    public static function tambahanKoreksi(array $budgetIds): array
    {
        $out = [];
        if (! $budgetIds) {
            return $out;
        }
        $daftar = BsBudgetCorrection::whereIn('budget_id', $budgetIds)->where('status', 'approved')->get();
        foreach ($daftar as $c) {
            $delta = round((float) $c->new_amount - (float) $c->old_amount, 2);
            $from = max(1, (int) $c->period_from);
            $to = min(12, (int) $c->period_to);
            $per = round($delta / max(1, $to - $from + 1), 2);
            for ($m = $from; $m <= $to; $m++) {
                $out[(int) $c->budget_id][$m] = round(($out[(int) $c->budget_id][$m] ?? 0.0) + $per, 2);
            }
        }

        return $out;
    }

    /**
     * @return array{bagian: list<array>, laba: array, tanpa_anggaran: bool}
     */
    public function susun(int $year, string $status, string $versi, int $sd): array
    {
        [$from, $to] = self::rentang($year, $sd);

        $akun = BsAccount::withTrashed()->get(['id', 'code', 'name', 'type', 'level', 'parent_id'])->keyBy('id');
        $pusat = BsCostCenter::withTrashed()->get(['id', 'code', 'name'])->keyBy('id');

        $budgets = BsBudget::where('year', $year)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderBy('doc_no')->get();
        $tambahan = self::tambahanKoreksi($budgets->pluck('id')->all());

        // Baris = akun × cost center ("semua" untuk anggaran lintas cost center, 0 = tanpa cost center).
        $baris = [];
        foreach ($budgets as $b) {
            $cc = $b->cost_center_id ? (string) (int) $b->cost_center_id : 'semua';
            $kunci = (int) $b->account_id.'|'.$cc;
            $bulan = self::bulanVersi($b, $versi, $tambahan);
            $baris[$kunci] ??= ['acc' => (int) $b->account_id, 'cc' => $cc, 'anggaran' => 0.0, 'setahun' => 0.0, 'budgets' => []];
            $baris[$kunci]['anggaran'] += array_sum(array_slice($bulan, 0, $sd, true));
            $baris[$kunci]['setahun'] += array_sum($bulan);
            $baris[$kunci]['budgets'][] = [
                'id' => $b->id, 'doc_no' => $b->doc_no, 'project' => $b->project, 'wf_status' => $b->status ?? 'draft',
                'notes' => $b->notes, 'approval_note' => $b->approval_note,
                'months' => array_values($b->monthsArray()),
            ];
        }

        // Realisasi: anggaran "Semua cost center" menyerap realisasi seluruh cost center akun itu
        // (sama dengan penjaga budget), jadi baris per cost center akun itu tidak dimunculkan lagi —
        // kalau dimunculkan, totalnya terhitung dua kali.
        $lintas = [];
        foreach ($baris as $r) {
            if ($r['cc'] === 'semua') {
                $lintas[$r['acc']] = true;
            }
        }
        $real = $this->realisasi->perCostCenter($year, $from, $to);
        // Realisasi tanpa anggaran hanya ikut ditampilkan saat melihat anggaran yang berlaku
        // (Disetujui / Semua) — untuk status Menunggu / Ditolak yang dilihat memang dokumennya.
        $ikutkanRealisasi = in_array($status, ['approved', 'all'], true);
        foreach ($real as $k => $v) {
            [$cc, $acc] = array_map('intval', explode('|', $k));
            if (isset($lintas[$acc])) {
                $baris[$acc.'|semua']['realisasi'] = ($baris[$acc.'|semua']['realisasi'] ?? 0.0) + $v;

                continue;
            }
            $kunci = $acc.'|'.$cc;
            if (! isset($baris[$kunci])) {
                $tipe = $akun[$acc]->type ?? null;
                if (! $ikutkanRealisasi || ! in_array($tipe, ['income', 'expense'], true) || abs($v) < 0.005) {
                    continue;
                }
                $baris[$kunci] = ['acc' => $acc, 'cc' => (string) $cc, 'anggaran' => 0.0, 'setahun' => 0.0, 'budgets' => []];
            }
            $baris[$kunci]['realisasi'] = ($baris[$kunci]['realisasi'] ?? 0.0) + $v;
        }

        // Susun pohon bagian → kelompok tingkat 3 → akun → cost center.
        $pohon = [];
        foreach ($baris as $r) {
            $a = $akun[$r['acc']] ?? null;
            $bagian = in_array($a?->type, ['income', 'expense'], true) ? $a->type : 'lainnya';
            $g = $this->kelompok($a, $akun);
            $ccNama = match (true) {
                $r['cc'] === 'semua' => 'Semua cost center',
                $r['cc'] === '0'     => 'Tanpa cost center',
                default              => (string) ($pusat[(int) $r['cc']]->name ?? 'Cost center #'.$r['cc']),
            };
            $pohon[$bagian][$g['kode']]['info'] = $g;
            $pohon[$bagian][$g['kode']]['akun'][$r['acc']]['info'] = ['id' => $r['acc'], 'kode' => (string) ($a?->code ?? $r['acc']), 'nama' => (string) ($a?->name ?? 'Akun #'.$r['acc'])];
            $pohon[$bagian][$g['kode']]['akun'][$r['acc']]['baris'][] = [
                'cc' => $r['cc'], 'cc_nama' => $ccNama, 'budgets' => $r['budgets'],
            ] + $this->angka($bagian, $r['anggaran'], $r['realisasi'] ?? 0.0, $r['setahun']);
        }

        $hasil = [];
        foreach (array_keys(self::BAGIAN) as $kode) {
            if (empty($pohon[$kode])) {
                continue;
            }
            ksort($pohon[$kode], SORT_NATURAL);
            $grup = [];
            foreach ($pohon[$kode] as $g) {
                $daftarAkun = [];
                uasort($g['akun'], fn ($x, $y) => strnatcmp($x['info']['kode'], $y['info']['kode']));
                foreach ($g['akun'] as $ak) {
                    usort($ak['baris'], fn ($x, $y) => strnatcasecmp($x['cc_nama'], $y['cc_nama']));
                    $daftarAkun[] = $ak['info'] + ['baris' => $ak['baris']] + $this->jumlah($kode, $ak['baris']);
                }
                $grup[] = $g['info'] + ['akun' => $daftarAkun] + $this->jumlah($kode, $daftarAkun);
            }
            $hasil[] = ['kode' => $kode, 'judul' => self::BAGIAN[$kode], 'grup' => $grup] + $this->jumlah($kode, $grup);
        }

        $nilai = fn (string $k, string $f) => (float) (collect($hasil)->firstWhere('kode', $k)[$f] ?? 0.0);
        $laba = $this->angka('income',
            $nilai('income', 'anggaran') - $nilai('expense', 'anggaran'),
            $nilai('income', 'realisasi') - $nilai('expense', 'realisasi'),
            $nilai('income', 'setahun') - $nilai('expense', 'setahun'));

        return ['bagian' => $hasil, 'laba' => $laba, 'tanpa_anggaran' => $budgets->isEmpty()];
    }

    /** Kelompok akun tingkat 3 (leluhur level 3); akun di atas tingkat 3 menjadi kelompoknya sendiri. */
    private function kelompok(?BsAccount $a, $akun): array
    {
        $n = $a;
        $jaga = 0;
        while ($n && (int) $n->level > 3 && $n->parent_id && isset($akun[$n->parent_id]) && $jaga++ < 10) {
            $n = $akun[$n->parent_id];
        }
        $n ??= $a;

        return ['kode' => (string) ($n?->code ?? '—'), 'nama' => (string) ($n?->name ?? 'Tanpa kelompok')];
    }

    /** Lima angka satu baris; selisih positif = lebih baik. */
    private function angka(string $bagian, float $anggaran, float $realisasi, float $setahun): array
    {
        $anggaran = round($anggaran, 2);
        $realisasi = round($realisasi, 2);

        return [
            'anggaran'  => $anggaran,
            'realisasi' => $realisasi,
            'selisih'   => round($bagian === 'income' ? $realisasi - $anggaran : $anggaran - $realisasi, 2),
            'persen'    => abs($anggaran) >= 0.005 ? round($realisasi / $anggaran * 100, 1) : null,
            'setahun'   => round($setahun, 2),
        ];
    }

    /** Jumlah anak-anaknya — rumus yang sama dengan baris, supaya total tidak mungkin berbeda. */
    private function jumlah(string $bagian, array $anak): array
    {
        $s = fn (string $f) => array_sum(array_column($anak, $f));

        return $this->angka($bagian, $s('anggaran'), $s('realisasi'), $s('setahun'));
    }
}

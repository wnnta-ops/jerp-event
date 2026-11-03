<?php

declare(strict_types=1);

/*
 * Uji Master Budget — Anggaran vs Realisasi Tahunan (BudgetAnnualReportService).
 *
 * Yang dijaga:
 *   · Anggaran s.d. = Σ m1..mN (sama dengan penjaga budget bulanan); setahun = Σ 12 bulan.
 *   · Realisasi s.d. = jurnal Jan..akhir bulan s.d.; jurnal sesudahnya tidak ikut.
 *   · Sisa = anggaran − realisasi (semua bagian).
 *   · Realisasi = sisi pengeluaran saja: jurnal kredit di akun beban tidak mengurangi realisasi.
 *   · Kelompok akun tingkat 3; total kelompok/bagian = jumlah anaknya; LABA = pendapatan − beban.
 *   · Anggaran "Semua cost center" menyerap realisasi semua cost center akun itu (tidak dobel).
 *   · Drill-down jurnal = angka realisasi barisnya.
 *
 * Data DB DIBATALKAN (rollback).
 *
 * Jalankan:  php tests/Manual/uji-budget-laporan-tahunan.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\BusinessSupport\Services\BudgetAnnualReportService;
use App\Domain\BusinessSupport\Services\BudgetRealizationService;
use Illuminate\Support\Facades\DB;

$tenant = (int) DB::table('tenants')->orderBy('id')->value('id');
app()->instance('tenant.id', $tenant);

$lulus = 0;
$gagal = 0;
$cek = function (string $nama, bool $ok, string $ket = '') use (&$lulus, &$gagal) {
    if ($ok) {
        $lulus++;
        echo "  OK    {$nama}\n";
    } else {
        $gagal++;
        echo "  GAGAL {$nama}".($ket !== '' ? " — {$ket}" : '')."\n";
    }
};
$sama = fn ($a, $b) => abs((float) $a - (float) $b) < 0.01;

$TAHUN = 2047;
$akun = fn (string $type, array $kecuali = []) => DB::table('bs_accounts')->where('tenant_id', $tenant)->where('is_postable', 1)
    ->where('type', $type)->where('cost_center_optional', 0)->where('level', '>', 3)->whereNotIn('id', $kecuali ?: [0])->orderBy('code')->first();
$beban = $akun('expense');
$beban2 = $akun('expense', [$beban->id ?? 0]);
$pend = $akun('income');
$ccs = DB::table('bs_cost_centers')->where('tenant_id', $tenant)->whereNull('deleted_at')->orderBy('code')->limit(2)->get();
if (! $beban || ! $beban2 || ! $pend || $ccs->count() < 2) {
    echo "Data contoh (akun / dua cost center) tidak lengkap — uji dihentikan.\n";
    exit(1);
}
[$ccA, $ccB] = [$ccs[0], $ccs[1]];

DB::beginTransaction();
DB::statement('SET FOREIGN_KEY_CHECKS=0');

try {
    $seqB = 0;
    $anggaran = function ($a, ?int $cc, float $perBulan) use (&$seqB, $tenant, $TAHUN): int {
        $seqB++;
        $id = 970000 + $seqB;
        $bulan = [];
        for ($m = 1; $m <= 12; $m++) {
            $bulan['m'.$m] = $perBulan;
        }
        DB::table('bs_budgets')->insert([
            'id' => $id, 'tenant_id' => $tenant, 'doc_no' => 'UJI-LT-'.$seqB, 'year' => $TAHUN, 'cost_center_id' => $cc,
            'account_id' => $a->id, 'amount' => $perBulan * 12, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ] + $bulan);

        return $id;
    };
    $seqJ = 0;
    $jurnal = function (string $tgl, $a, ?int $cc, float $debit, float $kredit) use (&$seqJ, $tenant, $TAHUN): void {
        $seqJ++;
        $jid = 960000 + $seqJ;
        DB::table('bs_journals')->insert([
            'id' => $jid, 'tenant_id' => $tenant, 'cost_center_id' => null, 'journal_number' => 'UJI-LT-JV-'.$seqJ,
            'journal_date' => $tgl, 'period_year' => $TAHUN, 'period_month' => (int) substr($tgl, 5, 2), 'source' => 'manual',
            'description' => 'Uji laporan tahunan', 'status' => 'final', 'total_debit' => $debit, 'total_credit' => $kredit,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('bs_journal_lines')->insert([
            'id' => $jid * 10, 'journal_id' => $jid, 'account_id' => $a->id, 'cost_center_id' => $cc, 'description' => 'uji',
            'debit' => $debit, 'credit' => $kredit, 'currency' => 'IDR', 'kurs' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $bA = $anggaran($beban, $ccA->id, 10_000_000);     // beban × A: 10 jt/bulan
    $anggaran($pend, $ccA->id, 20_000_000);             // pendapatan × A: 20 jt/bulan
    $anggaran($beban2, null, 5_000_000);                // beban2 × semua cost center: 5 jt/bulan

    $jurnal("{$TAHUN}-02-10", $beban, $ccA->id, 25_000_000, 0);
    $jurnal("{$TAHUN}-03-05", $beban, $ccA->id, 4_000_000, 0);
    $jurnal("{$TAHUN}-06-01", $beban, $ccA->id, 99_000_000, 0);   // sesudah s.d. Mar → tidak ikut
    $jurnal("{$TAHUN}-01-20", $pend, $ccA->id, 0, 70_000_000);
    $jurnal("{$TAHUN}-02-01", $beban2, $ccA->id, 3_000_000, 0);
    $jurnal("{$TAHUN}-02-02", $beban2, $ccB->id, 2_000_000, 0);
    $jurnal("{$TAHUN}-02-03", $beban, $ccB->id, 1_000_000, 0);    // realisasi tanpa anggaran
    $jurnal("{$TAHUN}-03-20", $beban, $ccA->id, 0, 2_000_000);    // kredit (pembatalan) → tidak mengurangi realisasi

    // Koreksi: +12 jt Jan–Des disetujui (1 jt/bulan), +50 jt menunggu (tidak dihitung).
    DB::table('bs_budget_corrections')->insert([
        ['tenant_id' => $tenant, 'doc_no' => 'UJI-KB-1', 'year' => $TAHUN, 'budget_id' => $bA, 'cost_center_id' => $ccA->id, 'account_id' => $beban->id,
            'corr_date' => "{$TAHUN}-01-02", 'period_from' => 1, 'period_to' => 12, 'old_amount' => 108_000_000, 'new_amount' => 120_000_000, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()],
        ['tenant_id' => $tenant, 'doc_no' => 'UJI-KB-2', 'year' => $TAHUN, 'budget_id' => $bA, 'cost_center_id' => $ccA->id, 'account_id' => $beban->id,
            'corr_date' => "{$TAHUN}-03-02", 'period_from' => 3, 'period_to' => 3, 'old_amount' => 120_000_000, 'new_amount' => 170_000_000, 'status' => 'baru', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $svc = app(BudgetAnnualReportService::class);
    $lap = $svc->susun($TAHUN, 'approved', 3);
    $bagian = fn (array $l, string $k) => collect($l['bagian'])->firstWhere('kode', $k);
    $cariAkun = function (array $l, string $k, int $id) use ($bagian) {
        foreach ($bagian($l, $k)['grup'] ?? [] as $g) {
            foreach ($g['akun'] as $a) {
                if ($a['id'] === $id) {
                    return $a + ['_grup' => $g];
                }
            }
        }

        return null;
    };
    $barisCc = fn (?array $a, string $cc) => collect($a['baris'] ?? [])->firstWhere('cc', $cc);

    echo "Anggaran berlaku, s.d. Mar {$TAHUN}\n";
    $a1 = $cariAkun($lap, 'expense', $beban->id);
    $r1 = $barisCc($a1, (string) $ccA->id);
    $cek('beban × A: anggaran s.d. = 3 × 10 jt', $sama($r1['anggaran'] ?? null, 30_000_000), json_encode($r1));
    $cek('beban × A: realisasi s.d. tanpa jurnal Juni & tanpa kredit pembatalan', $sama($r1['realisasi'] ?? null, 29_000_000), json_encode($r1));
    $cek('beban × A: sisa = anggaran − realisasi', $sama($r1['sisa'] ?? null, 1_000_000));
    $cek('beban × A: % = realisasi ÷ anggaran', $sama($r1['persen'] ?? null, 96.7));
    $cek('beban × A: anggaran setahun = 120 jt', $sama($r1['setahun'] ?? null, 120_000_000));
    $rB = $barisCc($a1, (string) $ccB->id);
    $cek('realisasi tanpa anggaran tetap tampil (beban × B)', $rB && $sama($rB['realisasi'], 1_000_000) && $sama($rB['anggaran'], 0) && $rB['persen'] === null);
    $cek('total akun = jumlah baris cost center', $sama($a1['realisasi'], 30_000_000) && $sama($a1['anggaran'], 30_000_000));

    $a2 = $cariAkun($lap, 'expense', $beban2->id);
    $cek('anggaran semua cost center menyerap realisasi A + B (satu baris)', count($a2['baris'] ?? []) === 1 && $sama($a2['baris'][0]['realisasi'] ?? null, 5_000_000), json_encode($a2['baris'] ?? null));

    $ap = $cariAkun($lap, 'income', $pend->id);
    $rp = $barisCc($ap, (string) $ccA->id);
    $cek('pendapatan: realisasi bertanda positif (kredit)', $sama($rp['realisasi'] ?? null, 70_000_000));
    $cek('pendapatan: sisa = anggaran − realisasi', $sama($rp['sisa'] ?? null, -10_000_000));

    $grupBeban = $a1['_grup'];
    $cek('kelompok = akun tingkat 3', DB::table('bs_accounts')->where('code', $grupBeban['kode'])->value('level') == 3 || $grupBeban['kode'] === $beban->code);
    $cek('total kelompok = jumlah akunnya', $sama($grupBeban['anggaran'], array_sum(array_column($grupBeban['akun'], 'anggaran'))));

    // Data lain di DB (tahun lain) tidak ikut karena tahunnya 2047; bagian hanya dari data uji.
    $tb = $bagian($lap, 'expense');
    $ti = $bagian($lap, 'income');
    $cek('LABA = pendapatan − beban (anggaran & realisasi)', $sama($lap['laba']['anggaran'], $ti['anggaran'] - $tb['anggaran']) && $sama($lap['laba']['realisasi'], $ti['realisasi'] - $tb['realisasi']));

    echo "\nKoreksi tidak dihitung dua kali\n";
    $cek('koreksi yang sudah diterapkan tidak ditambahkan lagi (anggaran = kolom bulan)', $sama($r1['anggaran'] ?? null, 30_000_000));

    echo "\nDrill-down jurnal\n";
    [$from, $to] = BudgetAnnualReportService::rentang($TAHUN, 3);
    $rin = app(BudgetRealizationService::class)->rincian($TAHUN, $beban->id, (int) $ccA->id, $from, $to);
    $cek('jurnal beban × A s.d. Mar = 2 baris (kredit tidak ikut), total = realisasi', count($rin) === 2 && $sama(array_sum(array_column($rin, 'nilai')), 29_000_000));
    $rinSemua = app(BudgetRealizationService::class)->rincian($TAHUN, $beban2->id, null, $from, $to);
    $cek('jurnal semua cost center = realisasi barisnya', $sama(array_sum(array_column($rinSemua, 'nilai')), 5_000_000));
    $rinP = app(BudgetRealizationService::class)->rincian($TAHUN, $pend->id, (int) $ccA->id, $from, $to);
    $cek('jurnal pendapatan bertanda positif', $sama($rinP[0]['nilai'] ?? null, 70_000_000));

    echo "\nStatus lain\n";
    $menunggu = $svc->susun($TAHUN, 'submitted', 3);
    $cek('status Menunggu: realisasi tanpa anggaran tidak dimunculkan', $menunggu['bagian'] === []);

    echo "\n".str_repeat('─', 78)."\n";
    echo $gagal === 0 ? "SEMUA LULUS ({$lulus})\n" : "GAGAL: {$gagal}, lulus: {$lulus}\n";
} catch (Throwable $e) {
    echo "\nGALAT: ".$e->getMessage()."\n  di ".$e->getFile().':'.$e->getLine()."\n";
    $gagal++;
} finally {
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
    DB::rollBack();
    echo "(data uji dibatalkan)\n";
}

exit($gagal === 0 ? 0 : 1);

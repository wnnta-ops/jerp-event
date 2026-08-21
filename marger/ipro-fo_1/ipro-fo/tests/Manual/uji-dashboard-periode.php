<?php

declare(strict_types=1);

/**
 * Uji: periode awal & periode akhir pada Dashboard Back Office.
 *
 * Jalankan:  docker exec ipro-fo-app php artisan tinker /var/www/html/tests/Manual/uji-dashboard-periode.php
 *
 * Yang diperiksa:
 *   1. Bawaannya tidak berubah — membuka dashboard tanpa memilih apa pun memberi delapan
 *      bulan terakhir, persis seperti sebelum periode bisa dipilih.
 *   2. Rentang terbalik ditukar, bukan memulangkan dashboard kosong.
 *   3. Sumbu bulan mengikuti rentang, dan rentang yang terlalu panjang dipotong SAMBIL
 *      mengatakannya.
 *   4. Kartu ALIRAN benar-benar tersaring: satu jurnal yang sama masuk saat periodenya
 *      mencakupnya dan hilang saat tidak.
 *   5. Kartu POSISI memakai TANGGAL AKHIR: saldo sebelum transaksi ≠ saldo sesudahnya, dan
 *      transaksi sebelum periode TETAP ikut membentuk saldo (kalau tidak, angkanya salah).
 *   6. Dua kartu yang memang tanpa dimensi waktu tidak berubah oleh periode.
 *
 * Semua yang menulis dibungkus transaksi lalu di-rollback.
 */

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Http\Controllers\Web\DashboardController;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$cek = function (string $nama, bool $ok, string $ket = '') use (&$lulus, &$gagal): void {
    if ($ok) {
        $lulus++;
        echo "  OK    {$nama}\n";
    } else {
        $gagal++;
        echo "  GAGAL {$nama}".($ket !== '' ? " — {$ket}" : '')."\n";
    }
};

$user = User::whereHas('roles')->first() ?: User::first();
auth()->setUser($user);

/** Panggil dashboard apa adanya dan pulangkan props-nya. */
$layar = function (array $param) use ($user): array {
    $req = Illuminate\Http\Request::create('/dashboard', 'GET', $param);
    $req->setUserResolver(fn () => $user);

    return app(DashboardController::class)($req)->toResponse($req)
        ->getOriginalContent()->getData()['page']['props'];
};

echo "\n=== 1. Bawaan tidak berubah ===\n";

$baku = $layar([]);
$hariIni = now()->toDateString();
$delapanBulan = now()->startOfMonth()->subMonths(7)->toDateString();

$cek('tanpa parameter → berakhir hari ini', $baku['periode']['akhir'] === $hariIni,
    (string) $baku['periode']['akhir']);
$cek('tanpa parameter → mulai delapan bulan lalu', $baku['periode']['awal'] === $delapanBulan,
    (string) $baku['periode']['awal']);
$cek('sumbu bulannya delapan', $baku['periode']['bulan'] === 8, (string) $baku['periode']['bulan']);
$cek('tidak ditandai terpotong', $baku['periode']['dipotong'] === false);
$cek('label periodenya terisi', is_string($baku['periode']['label']) && $baku['periode']['label'] !== '',
    (string) $baku['periode']['label']);
$cek('grafik bulanan punya delapan label',
    count($baku['charts']['laba_rugi']['labels']) === 8,
    (string) count($baku['charts']['laba_rugi']['labels']));

echo "\n=== 2. Rentang terbalik ditukar ===\n";

$terbalik = $layar(['date_from' => '2026-08-31', 'date_to' => '2026-06-01']);
$cek('awal & akhir ditukar',
    $terbalik['periode']['awal'] === '2026-06-01' && $terbalik['periode']['akhir'] === '2026-08-31',
    $terbalik['periode']['awal'].' s/d '.$terbalik['periode']['akhir']);
$cek('sumbu bulannya tiga (Jun, Jul, Agu)', $terbalik['periode']['bulan'] === 3,
    (string) $terbalik['periode']['bulan']);

echo "\n=== 3. Sumbu bulan mengikuti rentang ===\n";

$satuBulan = $layar(['date_from' => '2026-08-01', 'date_to' => '2026-08-31']);
$cek('rentang satu bulan → satu batang', $satuBulan['periode']['bulan'] === 1,
    (string) $satuBulan['periode']['bulan']);
$cek('grafik bulanan ikut satu label',
    count($satuBulan['charts']['laba_rugi']['labels']) === 1,
    (string) count($satuBulan['charts']['laba_rugi']['labels']));

$lintasTahun = $layar(['date_from' => '2025-11-01', 'date_to' => '2026-02-28']);
$cek('rentang lintas tahun → empat batang', $lintasTahun['periode']['bulan'] === 4,
    (string) $lintasTahun['periode']['bulan']);
// Tanpa tahun di label, "Nov" dan "Feb" tidak bisa dibedakan tahunnya.
$cek('label bulan membawa tahun saat rentangnya lintas tahun',
    (bool) collect($lintasTahun['charts']['laba_rugi']['labels'])->first(fn ($l) => str_contains((string) $l, "'")),
    implode(',', $lintasTahun['charts']['laba_rugi']['labels']));

$panjang = $layar(['date_from' => '2018-01-01', 'date_to' => '2026-12-31']);
$cek('rentang sangat panjang dipotong pada batas',
    $panjang['periode']['bulan'] === $panjang['periode']['maks_bulan'],
    $panjang['periode']['bulan'].' vs '.$panjang['periode']['maks_bulan']);
$cek('pemotongannya DIKATAKAN, bukan didiamkan', $panjang['periode']['dipotong'] === true);

echo "\n=== 4. Kartu ALIRAN benar-benar tersaring ===\n";

DB::beginTransaction();
try {
    $akunBeban = BsAccount::where('is_postable', true)->where('type', 'expense')->orderBy('code')->first();
    $akunKas   = BsAccount::where('is_postable', true)->where('type', 'asset')->orderBy('code')->first();
    $cek('akun uji tersedia', $akunBeban && $akunKas);

    // Satu jurnal posted bernilai besar & khas, di bulan yang jauh dari data lain.
    $tahun = 2033;
    $nilai = 777_777_777.0;
    $j = BsJournal::create([
        'journal_number' => 'UJI/DASH/1', 'journal_date' => "{$tahun}-05-15",
        'period_year' => $tahun, 'period_month' => 5,
        'source' => 'memorial', 'description' => 'UJI PERIODE DASHBOARD', 'status' => 'posted',
        'total_debit' => $nilai, 'total_credit' => $nilai,
    ]);
    $j->lines()->create(['account_id' => $akunBeban->id, 'debit' => $nilai, 'credit' => 0]);
    $j->lines()->create(['account_id' => $akunKas->id, 'debit' => 0, 'credit' => $nilai]);

    // Kartu Laba Rugi memulangkan {labels, vals} — satu deret, bukan {series}. Nilainya
    // pendapatan − beban, jadi jurnal beban muncul sebagai angka negatif; yang diperiksa
    // besarannya.
    $puncakLR = function (array $props): float {
        $vals = $props['charts']['laba_rugi']['vals'] ?? [];

        return $vals === [] ? 0.0 : (float) max(array_map(fn ($v) => abs((float) $v), $vals));
    };

    $mencakup = $layar(['date_from' => "{$tahun}-05-01", 'date_to' => "{$tahun}-05-31"]);
    $meleset  = $layar(['date_from' => "{$tahun}-06-01", 'date_to' => "{$tahun}-06-30"]);

    $cek('jurnal Mei terlihat saat periodenya Mei',
        abs($puncakLR($mencakup) - $nilai) < 1, (string) $puncakLR($mencakup));
    $cek('jurnal Mei HILANG saat periodenya Juni',
        $puncakLR($meleset) < 1, (string) $puncakLR($meleset));

    echo "\n=== 5. Kartu POSISI memakai tanggal akhir ===\n";

    $aktiva = function (array $props): float {
        $baris = collect($props['charts']['neraca']['rows'] ?? [])->firstWhere('n', 'AKTIVA');

        return (float) (($baris['a'] ?? 0) + ($baris['b'] ?? 0));
    };

    $sebelum = $layar(['date_from' => "{$tahun}-01-01", 'date_to' => "{$tahun}-05-14"]);
    $sesudah = $layar(['date_from' => "{$tahun}-01-01", 'date_to' => "{$tahun}-05-31"]);
    $cek('neraca berubah begitu tanggal akhirnya melewati transaksinya',
        abs($aktiva($sesudah) - $aktiva($sebelum)) > 1,
        $aktiva($sebelum).' → '.$aktiva($sesudah));

    // Ini yang membedakan POSISI dari ALIRAN: transaksi SEBELUM periode tetap membentuk saldo.
    $sempit = $layar(['date_from' => "{$tahun}-12-01", 'date_to' => "{$tahun}-12-31"]);
    $cek('transaksi sebelum periode TETAP ikut membentuk neraca',
        abs($aktiva($sempit) - $aktiva($sesudah)) < 1,
        $aktiva($sesudah).' vs '.$aktiva($sempit));

    echo "\n=== 6. Kartu tanpa dimensi waktu tidak terpengaruh ===\n";

    $a = $layar(['date_from' => "{$tahun}-01-01", 'date_to' => "{$tahun}-12-31"]);
    $b = $layar(['date_from' => '2020-01-01', 'date_to' => '2020-01-31']);
    $cek('Rekonsiliasi Piutang sama di dua periode berbeda',
        json_encode($a['charts']['piutang']) === json_encode($b['charts']['piutang']));
    $cek('Summary HPP sama di dua periode berbeda',
        json_encode($a['charts']['hpp']) === json_encode($b['charts']['hpp']));

    echo "\n=== 7. Seluruh kunci kartu tetap lengkap ===\n";
    // Index.vue men-dereference tiap kunci TANPA optional chaining — satu kunci hilang
    // menjatuhkan seluruh halaman, bukan satu kartu.
    $wajib = ['inisiasi_kpr', 'proses_kpr', 'penolakan', 'rekening', 'kasbon', 'aging', 'neraca',
        'laba_rugi', 'aset_tetap', 'budget', 'spk', 'progress', 'retensi', 'bast_ajb_br', 'piutang', 'hpp'];
    foreach ([$baku, $satuBulan, $panjang, $a] as $i => $props) {
        $kurang = array_values(array_diff($wajib, array_keys($props['charts'])));
        $cek('semua 16 kunci kartu ada (kasus '.($i + 1).')', $kurang === [], implode(',', $kurang));
    }
} finally {
    DB::rollBack();
}

echo "\n=== 8. Rollback bersih ===\n";
$cek('jurnal uji tidak tertinggal', BsJournal::where('journal_number', 'UJI/DASH/1')->count() === 0);

echo "\n==================================================\n";
echo "  LULUS {$lulus} · GAGAL {$gagal}\n";
echo "==================================================\n";

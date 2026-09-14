<?php

/**
 * EMPAT SUB-DASHBOARD BACK OFFICE — KPR & Collection · Cash Management ·
 * Accounting, Tax & Budget · Project.
 *
 * Delapan belas grafik pada satu halaman membuat yang dicari selalu jauh di bawah. Kini tiap
 * kelompok punya pintu masuknya sendiri lewat ?grup=, memakai halaman & controller yang SAMA —
 * satu tata letak, satu saringan periode, satu tempat memperbaiki bila ada yang salah.
 *
 * APA YANG DIJAGA DAN KENAPA.
 *   1. KEEMPATNYA HARUS MEMUAT SELURUH GRAFIK. Sejak item sidebar diberi children, induknya
 *      berubah menjadi tombol buka/tutup dan halaman gabungannya tidak lagi tertaut di sidebar.
 *      Grafik yang tidak masuk kelompok mana pun karena itu menjadi YATIM — tidak bisa dibuka
 *      dari mana pun, tanpa satu galat pun yang memberi tahu.
 *   2. TIDAK ADA GRAFIK YANG MUNCUL DI DUA KELOMPOK. Angka yang sama di dua tempat membuat
 *      orang mengira ada dua hal yang berbeda.
 *   3. SUB-DASHBOARD HANYA MENGHITUNG KELOMPOKNYA. Satu pemuatan penuh menjalankan puluhan
 *      kueri; menghitung keenam belas grafik untuk halaman yang menampilkan tiga adalah beban
 *      yang tidak pernah terlihat di layar, hanya terasa sebagai halaman yang lambat.
 *   4. TANPA ?grup= PERILAKUNYA PERSIS SEPERTI DULU — keenam belas kunci tetap dikirim,
 *      sehingga uji dashboard yang sudah ada tidak berubah artinya.
 *   5. ?grup= YANG NGAWUR diperlakukan sebagai halaman gabungan, bukan halaman kosong.
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-sub-dashboard.php';"
 */

use App\Http\Controllers\Web\DashboardController;
use App\Models\User;
use Illuminate\Http\Request;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};

$user = User::first();
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
auth()->setUser($user);

/** Panggil dashboard dan kembalikan props-nya. */
$props = function (array $query = []) use ($user): array {
    $r = Request::create('/dashboard', 'GET', $query, [], [], ['HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $r->setUserResolver(fn () => $user);
    app()->instance('request', $r);

    return app(DashboardController::class)($r)->toResponse($r)->getData(true)['props'];
};

echo "\n=== 1. KEEMPAT KELOMPOK MEMUAT SELURUH GRAFIK, TANPA TUMPANG TINDIH ===\n";

$penuh = $props();
$semuaKunci = array_keys($penuh['charts']);
$ok('halaman gabungan tetap mengirim 16 grafik', count($semuaKunci) === 16, (string) count($semuaKunci));

$grup = DashboardController::GRUP;
$ok('ada empat kelompok', count($grup) === 4, implode(', ', array_keys($grup)));

$gabungan = array_merge(...array_values($grup));
sort($gabungan);
$acuan = $semuaKunci;
sort($acuan);

/* Inilah penjaga terpentingnya: grafik yang terlewat tidak bisa dibuka dari mana pun. */
$yatim = array_values(array_diff($acuan, $gabungan));
$ok('TIDAK ada grafik yatim — semuanya kebagian kelompok', $yatim === [], implode(', ', $yatim));

$asing = array_values(array_diff($gabungan, $acuan));
$ok('tidak ada kunci kelompok yang tidak dikenal charts()', $asing === [], implode(', ', $asing));

$ok('tidak ada grafik yang muncul di dua kelompok',
    count($gabungan) === count(array_unique($gabungan)),
    implode(', ', array_keys(array_filter(array_count_values($gabungan), fn ($n) => $n > 1))));

echo "\n=== 2. ISI TIAP KELOMPOK SESUAI YANG DIMINTA ===\n";

$harus = [
    'kpr'        => ['inisiasi_kpr', 'proses_kpr', 'penolakan'],
    'cash'       => ['rekening', 'kasbon', 'aging'],
    'accounting' => ['neraca', 'laba_rugi', 'aset_tetap', 'budget', 'bast_ajb_br', 'piutang', 'hpp'],
    'project'    => ['spk', 'progress', 'retensi'],
];
foreach ($harus as $g => $kunci) {
    $a = $grup[$g] ?? [];
    sort($a);
    $b = $kunci;
    sort($b);
    $ok("kelompok '{$g}' berisi persis ".count($kunci).' grafik yang diminta', $a === $b,
        'ada: '.implode(', ', $a));
}

echo "\n=== 3. SUB-DASHBOARD HANYA MENGIRIM (DAN MENGHITUNG) KELOMPOKNYA ===\n";

foreach ($harus as $g => $kunci) {
    $p = $props(['grup' => $g]);
    $dikirim = array_keys($p['charts']);
    sort($dikirim);
    $diminta = $kunci;
    sort($diminta);

    $ok("?grup={$g} mengirim persis grafik kelompoknya", $dikirim === $diminta, implode(', ', $dikirim));
    $ok("?grup={$g} TIDAK ikut mengirim grafik kelompok lain",
        count($dikirim) < 16 && array_diff($dikirim, $diminta) === [], (string) count($dikirim));
    $ok("?grup={$g} menyebutkan judul kelompoknya di kepala halaman",
        ($p['grupJudul'] ?? null) === DashboardController::GRUP_JUDUL[$g], (string) ($p['grupJudul'] ?? 'null'));
    $ok("?grup={$g} mengirim penanda grup", ($p['grup'] ?? null) === $g, (string) ($p['grup'] ?? 'null'));
}

echo "\n=== 4. TANPA ?grup= PERILAKUNYA TIDAK BERUBAH ===\n";

/* `?? 'x'` TIDAK bisa dipakai menguji null — operatornya justru mengganti null itu sendiri,
   sehingga pemeriksaannya mustahil benar. Kuncinya diperiksa terpisah dari nilainya. */
$ok('grup null pada halaman gabungan',
    array_key_exists('grup', $penuh) && $penuh['grup'] === null, json_encode($penuh['grup'] ?? '(tidak ada kunci)'));
$ok('grupJudul null pada halaman gabungan',
    array_key_exists('grupJudul', $penuh) && $penuh['grupJudul'] === null, json_encode($penuh['grupJudul'] ?? '(tidak ada kunci)'));
$ok('periode tetap dikirim', isset($penuh['periode']['label']));

echo "\n=== 5. ?grup= YANG NGAWUR JATUH KE HALAMAN GABUNGAN ===\n";

/* Kelompok yang tidak dikenal TIDAK boleh memulangkan halaman kosong: layar tanpa satu grafik
   pun terbaca sebagai "datanya memang tidak ada", bukan sebagai alamat yang salah ketik. */
$ngawur = $props(['grup' => 'entah-apa']);
$ok('grafiknya tetap lengkap 16', count($ngawur['charts']) === 16, (string) count($ngawur['charts']));
$ok('penanda grupnya null', array_key_exists('grup', $ngawur) && $ngawur['grup'] === null,
    json_encode($ngawur['grup'] ?? '(tidak ada kunci)'));

echo "\n=== 6. SARINGAN PERIODE TETAP BEKERJA PADA SUB-DASHBOARD ===\n";

$dg = $props(['grup' => 'cash', 'date_from' => '2026-01-01', 'date_to' => '2026-06-30']);
$ok('kelompoknya tetap cash saat periode disaring', ($dg['grup'] ?? null) === 'cash', (string) ($dg['grup'] ?? 'null'));
$ok('grafiknya tetap tiga', count($dg['charts']) === 3, (string) count($dg['charts']));
$ok('periodenya ikut berubah', ($dg['periode']['awal'] ?? '') === '2026-01-01', (string) ($dg['periode']['awal'] ?? ''));

echo "\n──────────────────────────────────────────────────────────────────────────────\n";
echo $gagal === 0 ? "SEMUA LULUS ($lulus)\n" : "ADA YANG GAGAL — lulus $lulus, gagal $gagal\n";

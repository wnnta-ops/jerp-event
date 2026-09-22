<?php

/**
 * Master Data KPR — Collection Retensi disinkronkan dengan master Kategori Retensi, dan
 * mencatat PENCAIRAN + DAJAM YANG RELEASED dari modul Proses KPR.
 *
 * APA YANG SALAH SEBELUMNYA. Kolomnya dipatok delapan buah dengan pencocokan kata kunci
 * ("SKHGB", "BESTEK", …), lepas dari master. Akibatnya dua arah sekaligus:
 *   · kategori yang ADA di master tetapi tidak punya kolom (PONDASI, BAST + AJB) — pencairannya
 *     hilang tanpa jejak;
 *   · kolom yang TIDAK punya kategori (SKHGB) — selamanya kosong sambil tampak seperti data
 *     yang belum masuk.
 * Dan yang dicatat hanya pelepasan retensi; PENCAIRANNYA sendiri tidak pernah masuk sama sekali.
 * Tidak satu pun dari ketiganya menimbulkan galat — hanya angka yang tidak pernah muncul.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-kpr-retensi-sinkron.php';"
 */

use App\Domain\BankLoan\Models\RetentionCategory;
use App\Http\Controllers\Web\BankLoan\MasterDataKprController;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};

$user = User::all()->first(fn ($u) => (bool) $u->can('bankloan.application.view')) ?? User::first();
auth()->setUser($user);
$ctl = app(MasterDataKprController::class);

/** Panggil helper privat controller. */
$panggil = function (string $method, ...$args) use ($ctl) {
    $m = new ReflectionMethod(MasterDataKprController::class, $method);
    $m->setAccessible(true);

    return $m->invoke($ctl, ...$args);
};

echo "\n=== 1. KOLOM DIBENTUK DARI MASTER, BUKAN DAFTAR TETAP ===\n";

$masterAktif = DB::table('retention_categories')->whereNull('deleted_at')->where('is_active', 1)
    ->orderBy('sort_order')->orderBy('id')->get(['id', 'code', 'name', 'is_dajam', 'kpr_sync']);
$ok('ada kategori retensi aktif di master', $masterAktif->count() > 0, (string) $masterAktif->count());
if ($masterAktif->isEmpty()) { echo "\nMaster kosong — uji dihentikan.\n"; return; }

$judul = $panggil('retensiHeaders');

/* SATU KOLOM PER GRUP "Sinkron dengan Master Data KPR", bukan per kategori: TANAH MATANG
   50%/20%/30% berdiri sebagai satu kolom "TANAH MATANG". Kategori yang BELUM disinkronkan
   tetap punya kolomnya sendiri — dibuang, pencairannya hilang dari layar sementara masternya
   masih ada. Ditutup satu kolom semu "TANPA KATEGORI", jadi jumlahnya selalu grup + 1. */
$grup = [];
foreach ($masterAktif as $c) {
    $kunci = ($c->kpr_sync !== null && $c->kpr_sync !== '') ? $c->kpr_sync : ('x'.$c->id);
    $grup[$kunci] ??= ($c->kpr_sync !== null && $c->kpr_sync !== '')
        ? (RetentionCategory::KPR_SYNC[$c->kpr_sync] ?? trim((string) ($c->code ?: $c->name)))
        : trim((string) ($c->code ?: $c->name));
}
$ok('jumlah kolom = jumlah GRUP sinkron + 1 (TANPA KATEGORI)',
    count($judul) === count($grup) + 1,
    count($judul).' kolom vs '.count($grup).' grup');
$ok('judul kolom = nama grup sinkronnya, urut sesuai master',
    array_slice($judul, 0, -1) === array_values($grup), json_encode($judul));
$ok('  ditutup kolom TANPA KATEGORI', end($judul) === 'TANPA KATEGORI', json_encode($judul));
// Inilah penggabungan yang diminta: kategori ber-sinkron sama tidak berdiri dua kali.
$ok('  kategori yang bersinkron sama digabung jadi satu kolom',
    count($judul) - 1 < $masterAktif->count() || $masterAktif->pluck('kpr_sync')->unique()->count() === $masterAktif->count(),
    json_encode([$judul, $masterAktif->pluck('kpr_sync')->all()]));

// Kolom lama yang tidak punya kategori tidak boleh muncul lagi.
$ok('tidak ada lagi kolom "RETENSI 2 (SKHGB)" yang tak berkategori',
    ! in_array('RETENSI 2 (SKHGB)', $judul, true), json_encode($judul));
// Kategori yang dulu tidak punya kolom kini punya.
foreach (['PONDASI', 'BAST + AJB'] as $kode) {
    $c = $masterAktif->firstWhere('code', $kode);
    if ($c) {
        // Dicari lewat label GRUP-nya, bukan kodenya: "BAST + AJB" tampil sebagai "BAST & AJB"
        // begitu ia disinkronkan. Mencari kodenya akan gagal untuk alasan yang salah.
        $label = ($c->kpr_sync !== null && $c->kpr_sync !== '')
            ? (RetentionCategory::KPR_SYNC[$c->kpr_sync] ?? $kode) : $kode;
        $ok("kategori {$kode} tetap terwakili kolom \"{$label}\"", in_array($label, $judul, true), json_encode($judul));
    }
}

echo "\n=== 2. MENONAKTIFKAN KATEGORI IKUT MENGHILANGKAN KOLOMNYA ===\n";

DB::beginTransaction();
try {

$satu = $masterAktif->first();
DB::table('retention_categories')->where('id', $satu->id)->update(['is_active' => 0]);
// Cache statis di dalam controller: instance baru supaya masternya benar-benar dibaca ulang.
$judul2 = (function () {
    $c = app()->make(MasterDataKprController::class);
    $m = new ReflectionMethod(MasterDataKprController::class, 'retensiHeaders');
    $m->setAccessible(true);

    return $m->invoke($c);
})();
/* Kolom berkurang HANYA bila SELURUH anggota grupnya nonaktif. Menonaktifkan satu dari tiga
   tahapan TANAH MATANG tidak boleh menghilangkan kolomnya — pencairan tahapan lain masih ada
   di sana, dan kolom yang lenyap akan membawa serta uangnya tanpa satu pun tanda. */
$seGrup = $masterAktif->filter(fn ($c) => ($c->kpr_sync ?? ('x'.$c->id)) === ($satu->kpr_sync ?? ('x'.$satu->id)))->count();
$ok($seGrup > 1
        ? 'menonaktifkan SATU anggota grup TIDAK menghilangkan kolomnya'
        : 'kolomnya berkurang satu setelah satu-satunya anggota grup dinonaktifkan',
    count($judul2) === count($judul) - ($seGrup > 1 ? 0 : 1),
    count($judul2).' vs '.count($judul).' (anggota grup: '.$seGrup.')');
DB::table('retention_categories')->where('id', $satu->id)->update(['is_active' => 1]);

echo "\n=== 3. PENCAIRAN & DAJAM RELEASED TERCATAT ===\n";

$catCair = $masterAktif->firstWhere('is_dajam', 0);
$catDajam = $masterAktif->firstWhere('is_dajam', 1);
$ok('ada kategori non-DAJAM & kategori DAJAM di master', $catCair && $catDajam);
if (! $catCair || ! $catDajam) { echo "\nMaster kurang lengkap.\n"; DB::rollBack(); return; }

/* Fikstur: satu aplikasi KPR, satu pencairan berkategori, satu retensi, dua pelepasan.

   PEMERIKSAAN FOREIGN KEY DIMATIKAN SEMENTARA. Yang diuji berkas ini adalah cara
   retensiByBooking() merekap, bukan keabsahan rantai booking → customer → aplikasi. Membangun
   rantai itu lengkap berarti menyalin belasan tabel master hanya untuk mendapatkan satu baris
   pencairan — dan pada basis data yang bookings-nya memang kosong, uji ini akan selamanya
   terlewat. Semuanya tetap di dalam transaksi yang di-rollback, jadi tidak ada yang tertinggal. */
DB::statement('SET FOREIGN_KEY_CHECKS=0');
$appId = (int) DB::table('kpr_applications')->insertGetId([
    'tenant_id' => 1, 'booking_id' => 999001, 'status' => 'active',
    'application_number' => 'UJI-RET-'.substr((string) microtime(true), -8),
    // Kolom NOT NULL tanpa bawaan — diisi nilai semu; yang diuji berkas ini hanya rekap
    // retensinya, bukan keabsahan aplikasi KPR-nya.
    'customer_id' => 1, 'bank_partner_id' => 1, 'plafon_requested' => 0, 'submitted_at' => '2026-08-01',
    'created_at' => now(), 'updated_at' => now(),
]);
$realId = (int) DB::table('kpr_realizations')->insertGetId([
    'kpr_application_id' => $appId, 'retention_category_id' => $catCair->id,
    'disbursed_at' => '2026-08-06', 'disbursed_amount' => 213_150_000,
    'retention_amount' => 57_463_784, 'created_at' => now(), 'updated_at' => now(),
]);
$retId = (int) DB::table('kpr_retentions')->insertGetId([
    'kpr_realization_id' => $realId, 'amount' => 57_463_784, 'status' => 'released',
    'released_amount' => 21_180_000, 'created_at' => now(), 'updated_at' => now(),
]);
/* Urutan sisipnya SENGAJA tidak searah dengan tanggalnya: yang tanggalnya paling baru
   (2026-08-10) disisipkan LEBIH DULU, lalu yang lebih lama (2026-08-06). Dengan begitu "baris
   terakhir yang dibaca" bukan lagi "tanggal terbaru", sehingga pemilihan tanggal benar-benar
   teruji. Fikstur yang urut naik membuat kedua perilaku itu tampak sama — uji mutasi
   menemukannya. */
DB::table('kpr_retention_releases')->insert([
    ['kpr_retention_id' => $retId, 'amount' => 17_220_000, 'released_at' => '2026-08-10',
        'reason' => 'PENCAIRAN DAJAM STF', 'created_at' => now(), 'updated_at' => now()],
    ['kpr_retention_id' => $retId, 'amount' => 3_960_000, 'released_at' => '2026-08-06',
        'reason' => 'PENCAIRAN DAJAM PBG', 'created_at' => now(), 'updated_at' => now()],
]);

$peta = $panggil('retensiByBooking', [999001 => $appId]);
$sel = $peta[999001] ?? [];

$ok('pencairan tercatat pada kolom kategorinya sendiri',
    round((float) ($sel[$catCair->id]['n'] ?? 0), 2) === 213_150_000.0,
    json_encode($sel[$catCair->id] ?? null));
$ok('tanggal pencairannya ikut', ($sel[$catCair->id]['t'] ?? null) === '2026-08-06');

// Kedua pelepasan menumpuk di kolom DAJAM (alasannya "PBG"/"STF" — bukan PAJAK).
$dajamIds = $masterAktif->where('is_dajam', 1)->pluck('id')->all();
$totalDajam = 0.0;
foreach ($dajamIds as $id) { $totalDajam += (float) ($sel[$id]['n'] ?? 0); }
$ok('DAJAM yang released tercatat seluruhnya', round($totalDajam, 2) === 21_180_000.0, (string) $totalDajam);
$ok('tanggal DAJAM memakai pelepasan TERBARU, bukan yang terakhir dibaca',
    in_array('2026-08-10', array_map(fn ($id) => $sel[$id]['t'] ?? null, $dajamIds), true),
    json_encode(array_map(fn ($id) => $sel[$id]['t'] ?? null, $dajamIds)));

// Retensi yang MASIH DITAHAN tidak boleh ikut terhitung.
$ok('sisa retensi yang ditahan TIDAK ikut dihitung',
    round($totalDajam, 2) !== 57_463_784.0, 'yang ditahan ikut masuk');

echo "\n=== 4. PELEPASAN DIPETAKAN KE KATEGORI DAJAM YANG PALING SPESIFIK ===\n";

$catPajak = $masterAktif->first(fn ($c) => $c->is_dajam && str_contains(strtoupper((string) $c->code), 'PAJAK'));
if ($catPajak) {
    DB::table('kpr_retention_releases')->insert([
        'kpr_retention_id' => $retId, 'amount' => 34_783_784, 'released_at' => '2026-08-11',
        'reason' => 'PENCAIRAN DAJAM PAJAK', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $peta2 = $panggil('retensiByBooking', [999001 => $appId]);
    $sel2 = $peta2[999001] ?? [];
    $ok('"DAJAM PAJAK" masuk ke kolom DAJAM PAJAK, bukan DAJAM umum',
        round((float) ($sel2[$catPajak->id]['n'] ?? 0), 2) === 34_783_784.0,
        json_encode($sel2[$catPajak->id] ?? null));
    $umumId = $masterAktif->last(fn ($c) => $c->is_dajam && ! str_contains(strtoupper((string) $c->code), 'PAJAK'))?->id;
    if ($umumId) {
        $ok('kolom DAJAM umum tidak ikut menyedot yang PAJAK',
            round((float) ($sel2[$umumId]['n'] ?? 0), 2) === 21_180_000.0,
            json_encode($sel2[$umumId] ?? null));
    }
} else {
    echo "  (dilewati — master tidak punya kategori DAJAM PAJAK)\n";
}

echo "\n=== 5. PELEPASAN TANPA ALASAN TIDAK DIBUANG ===\n";

// Uang yang benar-benar dilepas tidak boleh hilang dari rekap hanya karena alasannya kosong.
$sblm = 0.0;
$petaA = $panggil('retensiByBooking', [999001 => $appId]);
foreach ($dajamIds as $id) { $sblm += (float) ($petaA[999001][$id]['n'] ?? 0); }
DB::table('kpr_retention_releases')->insert([
    'kpr_retention_id' => $retId, 'amount' => 1_500_000, 'released_at' => '2026-08-12',
    'reason' => null, 'created_at' => now(), 'updated_at' => now(),
]);
$petaB = $panggil('retensiByBooking', [999001 => $appId]);
$ssdh = 0.0;
foreach ($dajamIds as $id) { $ssdh += (float) ($petaB[999001][$id]['n'] ?? 0); }
$ok('pelepasan tanpa alasan tetap terhitung', round($ssdh - $sblm, 2) === 1_500_000.0,
    'selisih '.($ssdh - $sblm));

echo "\n=== 6. PENCAIRAN TANPA KATEGORI TIDAK MERUSAK APA PUN ===\n";

DB::table('kpr_realizations')->insert([
    'kpr_application_id' => $appId, 'retention_category_id' => null,
    'disbursed_at' => '2026-08-13', 'disbursed_amount' => 5_000_000,
    'retention_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
]);
$petaC = $panggil('retensiByBooking', [999001 => $appId]);
$ok('pencairan tanpa kategori tidak menambah kolom mana pun',
    round((float) ($petaC[999001][$catCair->id]['n'] ?? 0), 2) === 213_150_000.0,
    json_encode($petaC[999001][$catCair->id] ?? null));

echo "\n=== 7. BOOKING LAIN TIDAK IKUT TERISI ===\n";

$petaD = $panggil('retensiByBooking', [999001 => $appId, 999002 => 999999]);
$ok('booking tanpa aplikasi tetap kosong', ! isset($petaD[999002]));

} finally {
    DB::rollBack();
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
}

echo "\n=== 8. LAYAR TETAP MEMBACA KOLOM DARI SERVER ===\n";

$vue = file_get_contents(resource_path('js/Pages/BankLoan/MasterData/Index.vue')) ?: '';
$ok('kolom retensi dibangun dari props retensiCols',
    str_contains($vue, 'props.retensiCols.map((label, i) => pairTN(label, (r) => r.retensi?.[i]))'));

$src = file_get_contents(app_path('Http/Controllers/Web/BankLoan/MasterDataKprController.php')) ?: '';
$ok('daftar kolom tetap RETENSI_COLS sudah dihapus', ! str_contains($src, 'RETENSI_COLS'));
$ok('pencocokan kata kunci matchRetensiKey sudah dihapus', ! str_contains($src, 'matchRetensiKey'));
$ok('kolom dibaca dari tabel retention_categories', str_contains($src, "DB::table('retention_categories')"));
$ok('pencairan dibaca dari kpr_realizations', str_contains($src, "DB::table('kpr_realizations')"));
$ok('pelepasan dibaca dari kpr_retention_releases', str_contains($src, "kpr_retention_releases as rr"));
/* Slot retensi kini DIJUMLAH antar anggota grup, bukan dibaca dari satu id kategori.
   Inilah beda yang menentukan: nilai sel disimpan berkunci retention_category_id MENTAH,
   jadi kolom gabungan yang hanya membaca satu id wakil akan meninggalkan uang milik anggota
   lain pada kunci yang tak pernah dibaca siapa pun — lenyap dari rekap tanpa satu pun galat. */
$ok('slot retensi menjumlah seluruh anggota grup',
    str_contains($src, "\$this->selRetensiGrup(\$retensi[\$b->id] ?? [], \$col['ids'])"));
$ok('  tidak lagi membaca satu id kategori saja',
    ! str_contains($src, "\$retensi[\$b->id][\$col['id']]"));
// Judul dan nilai HARUS dari sumber yang sama: layar memasangkannya menurut INDEX.
$ok('judul & nilai kolom dirakit dari sumber yang sama (retensiKolom)',
    substr_count($src, '$this->retensiKolom()') >= 2);

echo "\n=== HASIL ===\n";
echo "  lulus: $lulus · gagal: $gagal\n";
echo $gagal === 0 ? "  SEMUA LULUS\n" : "  ADA YANG GAGAL\n";

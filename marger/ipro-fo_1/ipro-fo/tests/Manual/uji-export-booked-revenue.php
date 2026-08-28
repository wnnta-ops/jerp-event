<?php

declare(strict_types=1);

/**
 * Uji: tombol Export Excel pada seluruh sub modul Booked Revenue.
 *
 * Jalankan:  docker exec ipro-fo-app php artisan tinker /var/www/html/tests/Manual/uji-export-booked-revenue.php
 *
 * Tujuh ekspor diperiksa sekaligus. Yang paling menentukan BUKAN "berkasnya terbentuk",
 * melainkan:
 *   1. jumlah judul kolom SAMA dengan jumlah sel tiap baris — judul & sel dirakit di dua
 *      tempat terpisah, dan menggeser salah satunya memindahkan seluruh kolom di kanannya
 *      tanpa satu pun galat; berkasnya tetap terbuka mulus dengan angka di kolom yang salah;
 *   2. kolom identitas dipaksa dibaca sebagai TEKS — tanpa itu No. Faktur Pajak & NPWP
 *      berubah jadi notasi ilmiah dan nol depannya hilang;
 *   3. izinnya sama persis dengan layarnya, termasuk gerbang ATAU milik Batal Akad.
 */

use App\Http\Controllers\Web\BusinessSupport\BatalAkadController;
use App\Http\Controllers\Web\BusinessSupport\Revenue\BookedRevenueController;
use App\Http\Controllers\Web\BusinessSupport\Revenue\SalesInvoiceController;
use App\Http\Controllers\Web\BusinessSupport\Tax\FakturPajakController;
use App\Models\User;

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

$pengguna = fn (callable $syarat) => User::query()->get()->first($syarat);
$super = $pengguna(fn ($u) => $u->canModule('ar') && $u->canModule('tax'));
if (! $super) {
    echo "Tidak ada pengguna dengan akses modul ar + tax.\n";

    return;
}
auth()->setUser($super);

$req = function (array $q = []) use ($super) {
    $r = Illuminate\Http\Request::create('/x', 'GET', $q);
    $r->setUserResolver(fn () => $super);

    return $r;
};

/** Bedah satu berkas ekspor: jumlah judul, jumlah sel per baris, dan isinya. */
$bedah = function (\Illuminate\Http\Response $resp): array {
    $isi = $resp->getContent();
    preg_match_all('/<th[^>]*>/', $isi, $th);
    // DUA pencocokan yang berbeda, dan bedanya penting:
    //   $semuaTr — <tr> berattribut IKUT. Baris TOTAL selalu berattribut gaya, dan lebarnya
    //              justru paling mudah salah; sebelumnya ia tidak pernah diperiksa sama sekali.
    //   $trData  — hanya <tr> polos, yaitu baris isi. Dipakai menghitung ada-tidaknya data.
    preg_match_all('/<tr[^>]*>(?:(?!<\/tr>).)*<\/tr>/s', $isi, $semuaTr);
    preg_match_all('/<tr>(?:(?!<\/tr>).)*<\/tr>/s', $isi, $trData);

    $lebar = [];
    foreach ($semuaTr[0] as $baris) {
        $n = 0;
        preg_match_all('/<td[^>]*>/', $baris, $td);
        foreach ($td[0] as $sel) {
            $n += (int) (preg_match('/colspan="(\d+)"/', $sel, $m) ? $m[1] : 1);
        }
        $lebar[] = $n;
    }

    return [
        'isi'    => $isi,
        'judul'  => count($th[0]),
        'lebar'  => array_values(array_unique(array_filter($lebar))),
        'baris'  => count($trData[0]),
        'tipe'   => (string) $resp->headers->get('Content-Type'),
        'unduh'  => (string) $resp->headers->get('Content-Disposition'),
    ];
};

/** Pemeriksaan yang berlaku untuk SETIAP berkas ekspor. */
$umum = function (string $nama, array $b) use ($cek): void {
    $cek("{$nama}: bertipe Excel", str_contains($b['tipe'], 'vnd.ms-excel'), $b['tipe']);
    $cek("{$nama}: diunduh sebagai berkas .xls",
        str_contains($b['unduh'], 'attachment') && str_contains($b['unduh'], '.xls'), $b['unduh']);
    $cek("{$nama}: ada judul kolom", $b['judul'] > 0, (string) $b['judul']);
    // Inilah penjaga terpentingnya.
    $cek("{$nama}: tiap baris selebar jumlah judulnya",
        $b['lebar'] === [] || $b['lebar'] === [$b['judul']],
        $b['judul'].' judul vs lebar baris '.implode(',', $b['lebar']));
    // Hanya bermakna bila memang ADA baris data — penanda teks ditulis per sel, bukan di
    // kepala tabel. Tanpa syarat ini, berkas yang kebetulan kosong akan dilaporkan gagal
    // karena alasan yang salah.
    if ($b['baris'] > 0) {
        $cek("{$nama}: kolom identitas dipaksa jadi teks",
            str_contains($b['isi'], 'mso-number-format'), 'tidak ada penanda teks');
    } else {
        echo "  (lewat) {$nama}: belum ada baris data untuk memeriksa penanda teks\n";
    }
};

echo "\n=== 1. Belum Booked Revenue ===\n";
$b = $bedah(app(BookedRevenueController::class)->belumBookedExport($req()));
$umum('Belum Booked', $b);
$cek('Belum Booked: kolom Siap Booking ada', str_contains($b['isi'], 'Siap Booking'));
$cek('Belum Booked: baris TOTAL menyebut yang siap di-booking',
    str_contains($b['isi'], 'siap di-booking'), 'tidak ditemukan');

echo "\n=== 2. Detail BAST ===\n";
$b = $bedah(app(BookedRevenueController::class)->exportControlBast($req()));
$umum('Detail BAST', $b);
$cek('Detail BAST: kolom Outstanding ada', str_contains($b['isi'], 'Outstanding (hari)'));
$semua = $b['baris'];
// Saringan Sudah/Belum tidak pernah masuk URL; tombolnya yang mengirimkannya.
$hanyaBelum = $bedah(app(BookedRevenueController::class)->exportControlBast($req(['kind' => 'belum'])));
$cek('Detail BAST: saringan kind menyempitkan hasilnya',
    $hanyaBelum['baris'] <= $semua, $hanyaBelum['baris'].' vs '.$semua);
$cek('Detail BAST: dengan kind=belum tidak ada baris "Sudah BAST"',
    ! str_contains($hanyaBelum['isi'], '>Sudah BAST<'), 'masih ada baris sudah');

echo "\n=== 3. Detail AJB ===\n";
$b = $bedah(app(BookedRevenueController::class)->exportControlAjb($req()));
$umum('Detail AJB', $b);
$cek('Detail AJB: kolom Status Posting ada', str_contains($b['isi'], 'Status Posting'));

echo "\n=== 4. PPN Keluaran ===\n";
$b = $bedah(app(FakturPajakController::class)->exportKeluaranXlsx($req(['year' => 2026, 'month' => 8])));
$umum('PPN Keluaran', $b);
$cek('PPN Keluaran: masa pajaknya disebutkan', str_contains($b['isi'], '08/2026'), 'tidak ditemukan');
// Saringan kolom di layar dihitung di peramban dan tidak ikut; itu dikatakan, bukan didiamkan.
$cek('PPN Keluaran: dikatakan bahwa saringan kolom layar tidak diterapkan',
    str_contains($b['isi'], 'saringan kolom di layar tidak diterapkan'), 'tidak ditemukan');
$cek('PPN Keluaran: kolom Status Konversi ada', str_contains($b['isi'], 'Status Konversi'));

echo "\n=== 5. Rincian Batal Akad ===\n";
$b = $bedah(app(BatalAkadController::class)->export($req()));
$umum('Batal Akad', $b);
$cek('Batal Akad: kolom Tahap Sebelum Batal ada', str_contains($b['isi'], 'Tahap Sebelum Batal'));

echo "\n=== 6. Invoice Penjualan ===\n";
$b = $bedah(app(SalesInvoiceController::class)->export($req()));
$umum('Invoice Penjualan', $b);
$cek('Invoice Penjualan: kolom Grand Total ada', str_contains($b['isi'], 'Grand Total'));
// Ekspor TIDAK boleh ikut terpotong paginasi 50 baris — berkas yang terpotong terbaca
// sebagai "memang segitu datanya".
$jml = App\Domain\BusinessSupport\Models\BsBookedRevenue::where('status', '!=', 'cancelled')->count();
$cek('Invoice Penjualan: seluruh record ikut, bukan satu halaman',
    str_contains($b['isi'], 'TOTAL — '.$jml.' unit'), 'jumlah record '.$jml);

echo "\n=== 7. Report / Query — dulu CSV, kini Excel ===\n";
$b = $bedah(app(BookedRevenueController::class)->reportExport($req()));
$umum('Report', $b);
$cek('Report: kolom Kode Customer ada (hilang di CSV lama)',
    str_contains($b['isi'], 'Kode Customer'), 'tidak ditemukan');
$cek('Report: kolom Nominal Faktur Pajak ada', str_contains($b['isi'], 'Nominal Faktur Pajak'));
$cek('Report: baris totalnya menyebut jumlah unit', str_contains($b['isi'], 'unit)'), 'tidak ditemukan');

echo "\n=== 8. Izin dijaga terpisah per layar ===\n";

// Batal Akad memakai gerbang ATAU: pengguna KPR yang tidak punya modul businesssupport
// TETAP boleh mengunduhnya. Menyeragamkan izin akan menutup pintu bagi yang paling sering
// memakainya.
$kpr = $pengguna(fn ($u) => ! $u->canModule('businesssupport') && $u->can('bankloan.application.view'));
if ($kpr) {
    $r = Illuminate\Http\Request::create('/x', 'GET');
    $r->setUserResolver(fn () => $kpr);
    $boleh = true;
    try {
        app(BatalAkadController::class)->export($r);
    } catch (\Throwable $e) {
        $boleh = false;
    }
    $cek('Batal Akad: pengguna KPR tanpa modul businesssupport tetap boleh mengunduh', $boleh);
} else {
    echo "  (lewat) tidak ada pengguna KPR-tanpa-businesssupport untuk diuji\n";
}

$tanpa = $pengguna(fn ($u) => ! $u->canModule('ar'));
if ($tanpa) {
    $r = Illuminate\Http\Request::create('/x', 'GET');
    $r->setUserResolver(fn () => $tanpa);
    $ditolak = false;
    try {
        app(SalesInvoiceController::class)->export($r);
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        $ditolak = $e->getStatusCode() === 403;
    }
    $cek('Invoice Penjualan: tanpa modul ar ditolak 403', $ditolak);
} else {
    echo "  (lewat) semua pengguna punya modul ar\n";
}

echo "\n==================================================\n";
echo "  LULUS {$lulus} · GAGAL {$gagal}\n";
echo "==================================================\n";

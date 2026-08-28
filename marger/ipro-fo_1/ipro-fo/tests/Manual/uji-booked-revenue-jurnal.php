<?php

declare(strict_types=1);

/**
 * Uji: kolom No. Jurnal & ekspor Excel pada Sudah Booked Revenue.
 *
 * Jalankan:  docker exec ipro-fo-app php artisan tinker /var/www/html/tests/Manual/uji-booked-revenue-jurnal.php
 *
 * Satu Booked Revenue bisa melahirkan DUA jurnal bernomor berbeda: jurnal Booking (saat BR
 * dibuat) dan jurnal Pengakuan (saat pendapatannya diakui). Menampilkan salah satunya saja
 * membuat yang lain seolah tidak pernah ada.
 *
 * Semua yang menulis dibungkus transaksi lalu di-rollback.
 */

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsBookedRevenue;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Http\Controllers\Web\BusinessSupport\Revenue\BookedRevenueController;
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

$user = User::query()->get()->first(fn ($u) => $u->canModule('ar'));
if (! $user) {
    echo "Tidak ada pengguna dengan akses modul ar.\n";

    return;
}
auth()->setUser($user);

$permintaan = function (array $q = []) use ($user) {
    $req = Illuminate\Http\Request::create('/bs/booked-revenue', 'GET', $q);
    $req->setUserResolver(fn () => $user);

    return $req;
};
$layar = function (array $q = []) use ($permintaan): array {
    $req = $permintaan($q);

    return app(BookedRevenueController::class)->index($req)->toResponse($req)
        ->getOriginalContent()->getData()['page']['props'];
};
$baris = function (array $props, int $id): ?array {
    foreach ($props['rows']['data'] as $r) {
        if ((int) $r['id'] === $id) {
            return $r;
        }
    }

    return null;
};

DB::beginTransaction();
try {
    $akun = BsAccount::where('is_postable', true)->orderBy('code')->limit(3)->get();
    $cek('tiga akun tersedia', $akun->count() === 3);

    $nomorJ = 0;
    $buatJurnal = function (string $tanggal) use ($akun, &$nomorJ) {
        $nomorJ++;
        $j = BsJournal::create([
            'journal_number' => 'UJI/BRJ/'.str_pad((string) $nomorJ, 4, '0', STR_PAD_LEFT),
            'journal_date' => $tanggal, 'period_year' => (int) substr($tanggal, 0, 4),
            'period_month' => (int) substr($tanggal, 5, 2),
            'source' => 'ar', 'description' => 'Uji BR', 'status' => 'posted',
            'total_debit' => 100, 'total_credit' => 100,
        ]);
        $j->lines()->create(['account_id' => $akun[0]->id, 'debit' => 100, 'credit' => 0]);
        $j->lines()->create(['account_id' => $akun[1]->id, 'debit' => 0, 'credit' => 100]);

        return $j;
    };

    $nomorBr = 0;
    $buatBr = function (array $extra = []) use ($akun, &$nomorBr) {
        $nomorBr++;

        return BsBookedRevenue::create(array_merge([
            'br_number' => 'UJI/BR/'.str_pad((string) $nomorBr, 4, '0', STR_PAD_LEFT),
            'booking_date' => '2026-07-19',
            'customer_id' => 1, 'unit_id' => 1,
            'gross_price' => 506_000_000, 'discount' => 0, 'amount' => 455_855_856,
            'receivable_account_id' => $akun[0]->id,
            'deferred_account_id'   => $akun[1]->id,
            'income_account_id'     => $akun[2]->id,
            'status' => 'booked',
        ], $extra));
    };

    echo "\n=== 1. BR tanpa jurnal ===\n";

    $tanpa = $buatBr();
    $r = $baris($layar(), (int) $tanpa->id);
    $cek('barisnya ada di daftar', $r !== null);
    $cek('journals kosong', ($r['journals'] ?? null) === [], json_encode($r['journals'] ?? null));

    echo "\n=== 2. BR dengan jurnal booking ===\n";

    $jb = $buatJurnal('2026-07-19');
    $satu = $buatBr(['booking_journal_id' => $jb->id]);
    $r = $baris($layar(), (int) $satu->id);
    $cek('satu jurnal terbawa', count($r['journals']) === 1, (string) count($r['journals']));
    $cek('nomornya benar', $r['journals'][0]['number'] === $jb->journal_number, (string) $r['journals'][0]['number']);
    $cek('jenisnya Booking', $r['journals'][0]['jenis'] === 'Booking', (string) $r['journals'][0]['jenis']);
    // idnya dipakai layar untuk menaut ke detail jurnalnya.
    $cek('idnya ikut dikirim', (int) $r['journals'][0]['id'] === (int) $jb->id);

    echo "\n=== 3. BR dengan dua jurnal — keduanya disebut ===\n";

    $jp = $buatJurnal('2026-12-31');
    $dua = $buatBr(['booking_journal_id' => $jb->id, 'recognition_journal_id' => $jp->id, 'status' => 'recognized']);
    $r = $baris($layar(), (int) $dua->id);
    $cek('dua jurnal terbawa', count($r['journals']) === 2, (string) count($r['journals']));
    // Urutannya tetap: booking dulu, baru pengakuan — mengikuti urutan kejadiannya.
    $cek('urutannya Booking lalu Pengakuan',
        $r['journals'][0]['jenis'] === 'Booking' && $r['journals'][1]['jenis'] === 'Pengakuan',
        $r['journals'][0]['jenis'].' lalu '.$r['journals'][1]['jenis']);
    $cek('nomor pengakuan benar', $r['journals'][1]['number'] === $jp->journal_number);

    echo "\n=== 4. Bukan N+1 ===\n";

    $hitung = function () use ($layar): int {
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        $layar();

        return $n;
    };
    $sedikit = $hitung();
    for ($i = 0; $i < 10; $i++) {
        $a = $buatJurnal('2026-07-19');
        $b = $buatJurnal('2026-12-31');
        $buatBr(['booking_journal_id' => $a->id, 'recognition_journal_id' => $b->id, 'status' => 'recognized']);
    }
    $banyak = $hitung();
    $cek('menambah 10 BR + 20 jurnal tidak menambah jumlah query',
        $banyak === $sedikit, $sedikit.' → '.$banyak);

    echo "\n=== 5. Ekspor Excel ===\n";

    $req = $permintaan();
    $resp = app(BookedRevenueController::class)->export($req);
    $isi = $resp->getContent();

    $cek('bertipe Excel', str_contains((string) $resp->headers->get('Content-Type'), 'vnd.ms-excel'),
        (string) $resp->headers->get('Content-Type'));
    $cek('diunduh sebagai berkas', str_contains((string) $resp->headers->get('Content-Disposition'), 'attachment'),
        (string) $resp->headers->get('Content-Disposition'));
    $cek('judul kolom No. Jurnal ada', str_contains($isi, '<th>No. Jurnal</th>'));
    $cek('nomor jurnal benar-benar tertulis', str_contains($isi, $jb->journal_number), 'tidak ditemukan');
    $cek('jenis jurnal ikut ditulis', str_contains($isi, '(Booking)') && str_contains($isi, '(Pengakuan)'));
    $cek('nomor BR ikut tertulis', str_contains($isi, $satu->br_number));

    // Judul & sel dirakit di dua tempat terpisah — jumlahnya harus sama, kalau tidak seluruh
    // kolom di kanannya bergeser dan angkanya duduk di kolom yang salah.
    preg_match_all('/<th>/', $isi, $mth);
    preg_match_all('/<tr>(?:(?!<\/tr>).)*<\/tr>/s', $isi, $mtr);
    $selPerBaris = array_map(fn ($t) => substr_count($t, '<td>'), $mtr[0]);
    $cek('jumlah sel tiap baris sama dengan jumlah judul',
        $selPerBaris !== [] && count(array_unique($selPerBaris)) === 1 && $selPerBaris[0] === count($mth[0]),
        count($mth[0]).' judul vs sel '.implode(',', array_unique($selPerBaris)));

    echo "\n=== 6. Ekspor mengikuti pencarian, dan TIDAK dipotong paginasi ===\n";

    // Layarnya memakai paginate(50); berkas yang ikut terpotong akan terbaca sebagai
    // "memang segitu datanya" dan tidak cocok dengan kartu ringkasan.
    $jml = BsBookedRevenue::where('status', '!=', 'cancelled')->count();
    $cek('seluruh record ikut terekspor', str_contains($isi, "TOTAL ({$jml} record)"), (string) $jml);

    $isiCari = app(BookedRevenueController::class)->export($permintaan(['search' => $satu->br_number]))->getContent();
    $cek('pencarian dihormati', str_contains($isiCari, $satu->br_number));
    $cek('yang tidak cocok tidak ikut', ! str_contains($isiCari, $tanpa->br_number),
        'BR lain masih ikut terbawa');
} finally {
    DB::rollBack();
}

echo "\n=== 7. Rollback bersih ===\n";
$cek('BR uji tidak tertinggal', BsBookedRevenue::where('br_number', 'like', 'UJI/BR/%')->count() === 0);
$cek('jurnal uji tidak tertinggal', BsJournal::where('journal_number', 'like', 'UJI/BRJ/%')->count() === 0);

echo "\n==================================================\n";
echo "  LULUS {$lulus} · GAGAL {$gagal}\n";
echo "==================================================\n";

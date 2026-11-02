<?php

declare(strict_types=1);

/**
 * Uji: gerbang anggaran (over-budget) + saringan kolom pada Detail Transaksi Jurnal.
 *
 * CATATAN: kolom "Anggaran" pada layar sudah DIGANTI menjadi kolom Cost Center, jadi keterangan
 * over/under tidak lagi dibaca dari props layar. Aturannya tetap ditegakkan BudgetGuardService
 * saat input, dan pemeriksaannya dipindahkan ke sana — bukan dibuang.
 *
 * Jalankan:  docker exec ipro-fo-app php artisan tinker /var/www/html/tests/Manual/uji-jurnal-anggaran-kolom.php
 *
 * Yang diperiksa:
 *   1. Hanya anggaran BERSTATUS DISETUJUI yang mengikat gerbang.
 *   2. Anggaran lintas cost center (cost_center_id kosong) berlaku untuk cost center mana pun.
 *   3. Gerbang over-budget benar-benar MENOLAK input pada akun yang sudah over, dan
 *      MENERIMA pada yang masih under.
 *   4. Saringan kolom (No. Jurnal, Tanggal, Akun, Keterangan Baris, Debit, Cost Center)
 *      menyaring dengan benar, dan tiap kolom punya isi pilihannya.
 *   5. Data uji tidak tertinggal setelah rollback.
 *
 * Semua yang menulis dibungkus transaksi lalu di-rollback.
 */

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsBudget;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Domain\BusinessSupport\Services\BudgetGuardService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Web\BusinessSupport\Gl\JournalController;
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

/** Panggil layar Detail Transaksi Jurnal apa adanya dan pulangkan props-nya. */
$layar = function (array $param) use ($user): array {
    $req = Illuminate\Http\Request::create('/business-support/gl/journal-detail', 'GET', $param);
    $req->setUserResolver(fn () => $user);
    $req->setRouteResolver(fn () => new class
    {
        public function named(...$n)
        {
            return in_array('bs.gl.journal.detail', $n, true);
        }
    });

    return app(JournalController::class)->index($req)->toResponse($req)
        ->getOriginalContent()->getData()['page']['props'];
};

/** Baris jurnal dari props — Inertia sudah menserialisasi paginatornya jadi array biasa. */
$isi = function (array $props) {
    $j = $props['journals'];
    $rows = is_array($j) ? ($j['data'] ?? []) : $j->items();

    return collect($rows)->map(fn ($r) => is_array($r) ? (object) $r : $r);
};

DB::beginTransaction();
try {
    $tahun = 2031;                       // tahun yang pasti kosong, agar tak tercampur data lain
    $dari = "{$tahun}-01-01";
    $sampai = "{$tahun}-12-31";

    $cc = BsCostCenter::first() ?: BsCostCenter::create(['code' => 'UJI', 'name' => 'UJI CC', 'is_active' => true]);
    $cc2 = BsCostCenter::where('id', '!=', $cc->id)->first();
    $akun = BsAccount::where('is_postable', true)->where('type', 'expense')->orderBy('code')->limit(3)->get();
    $cek('ada tiga akun beban postable', $akun->count() === 3, (string) $akun->count());
    [$akOver, $akUnder, $akTanpa] = [$akun[0], $akun[1], $akun[2]];

    $buatJurnal = function (string $no, $ak, ?int $ccId, float $nilai) use ($tahun) {
        $lawan = BsAccount::where('is_postable', true)->where('type', 'liability')->first();
        $j = BsJournal::create([
            'cost_center_id' => $ccId, 'journal_number' => $no,
            'journal_date' => "{$tahun}-03-10", 'period_year' => $tahun, 'period_month' => 3,
            'source' => 'memorial', 'description' => 'UJI ANGGARAN '.$no, 'status' => 'final',
            'total_debit' => $nilai, 'total_credit' => $nilai,
        ]);
        $j->lines()->create(['account_id' => $ak->id, 'cost_center_id' => $ccId, 'debit' => $nilai, 'credit' => 0, 'description' => 'baris uji '.$no]);
        $j->lines()->create(['account_id' => $lawan->id, 'debit' => 0, 'credit' => $nilai, 'description' => 'lawan uji']);

        return $j;
    };

    // Anggaran 1.000.000, realisasi 1.500.000 → OVER.
    BsBudget::create(['doc_no' => 'UJI-OVER', 'year' => $tahun, 'account_id' => $akOver->id,
        'cost_center_id' => $cc->id, 'amount' => 1_000_000, 'status' => 'approved']);
    $jOver = $buatJurnal('UJI/OVER/1', $akOver, (int) $cc->id, 1_500_000);

    // Anggaran 10.000.000, realisasi 2.000.000 → UNDER.
    BsBudget::create(['doc_no' => 'UJI-UNDER', 'year' => $tahun, 'account_id' => $akUnder->id,
        'cost_center_id' => $cc->id, 'amount' => 10_000_000, 'status' => 'approved']);
    $jUnder = $buatJurnal('UJI/UNDER/1', $akUnder, (int) $cc->id, 2_000_000);

    // Tanpa anggaran sama sekali.
    $jTanpa = $buatJurnal('UJI/TANPA/1', $akTanpa, (int) $cc->id, 3_000_000);

    /* KOLOM "ANGGARAN" PADA LAYAR SUDAH DIHAPUS (diganti kolom Cost Center), jadi keterangan
       over/under tidak lagi bisa dibaca dari props layar. Aturannya sendiri TIDAK ikut hilang:
       ia tetap ditegakkan BudgetGuardService saat input. Karena itu pemeriksaan di bawah
       DIPINDAHKAN ke gerbang itu, bukan dibuang — yang berubah cara membacanya, bukan
       aturannya. Menghapusnya begitu saja akan melepas dua aturan anggaran dari pengujian
       tanpa ada satu pun berkas yang menandainya. */

    $gerbang = app(BudgetGuardService::class);
    /** Jalankan gerbang; pulangkan exception-nya (null = diterima). */
    $ujiGerbang = function ($akun, ?int $ccId, float $nilai) use ($gerbang, $tahun) {
        try {
            $gerbang->assertWithinBudget(
                [['account_id' => $akun->id, 'cost_center_id' => $ccId, 'debit' => $nilai, 'credit' => 0]],
                null, "{$tahun}-04-01",
            );

            return null;
        } catch (BusinessRuleException $e) {
            return $e;
        }
    };

    echo "\n=== 1. Hanya anggaran DISETUJUI yang mengikat ===\n";

    $draft = BsBudget::create(['doc_no' => 'UJI-DRAFT', 'year' => $tahun, 'account_id' => $akTanpa->id,
        'cost_center_id' => $cc->id, 'amount' => 1, 'status' => 'draft']);
    $cek('anggaran berstatus draft tidak mengikat', $ujiGerbang($akTanpa, (int) $cc->id, 1000) === null,
        (string) $ujiGerbang($akTanpa, (int) $cc->id, 1000)?->getMessage());

    $draft->update(['status' => 'approved']);
    $cek('begitu disetujui, anggaran itu langsung mengikat',
        $ujiGerbang($akTanpa, (int) $cc->id, 1000)?->errorCode() === 'OVER_BUDGET',
        (string) $ujiGerbang($akTanpa, (int) $cc->id, 1000)?->errorCode());
    $draft->delete();

    echo "\n=== 2. Anggaran lintas cost center ===\n";

    if ($cc2) {
        $global = BsBudget::create(['doc_no' => 'UJI-GLOBAL', 'year' => $tahun, 'account_id' => $akTanpa->id,
            'cost_center_id' => null, 'amount' => 100_000, 'status' => 'approved']);
        $cek('anggaran tanpa cost center mengikat cost center mana pun',
            $ujiGerbang($akTanpa, (int) $cc2->id, 500_000)?->errorCode() === 'OVER_BUDGET',
            (string) $ujiGerbang($akTanpa, (int) $cc2->id, 500_000)?->errorCode());
        $global->delete();
    } else {
        echo "  (dilewati — hanya ada satu cost center di master)\n";
    }

    echo "\n=== 3. Gerbang input benar-benar menolak yang over ===\n";

    $gerbang = app(BudgetGuardService::class);
    $tolak = null;
    try {
        $gerbang->assertWithinBudget(
            [['account_id' => $akOver->id, 'cost_center_id' => $cc->id, 'debit' => 1000, 'credit' => 0]],
            // Penjaga budget BULANAN: input di bulan yang sama dengan realisasinya (Maret) — sisa
            // bulan lain tidak dipakai menutup bulan ini.
            null, "{$tahun}-03-15",
        );
    } catch (BusinessRuleException $e) {
        $tolak = $e;
    }
    $cek('input pada akun yang sudah over DITOLAK', $tolak !== null);
    $cek('penolakannya bertanda OVER_BUDGET', $tolak?->errorCode() === 'OVER_BUDGET', (string) $tolak?->errorCode());
    $cek('pesan penolakannya menyebut anggaran & sisanya',
        str_contains((string) $tolak?->getMessage(), 'anggaran')
        && str_contains((string) $tolak?->getMessage(), 'sisa'), (string) $tolak?->getMessage());

    $terima = true;
    try {
        $gerbang->assertWithinBudget(
            [['account_id' => $akUnder->id, 'cost_center_id' => $cc->id, 'debit' => 1000, 'credit' => 0]],
            // April: realisasi Maret tidak memakan anggaran April (penjaga bulanan).
            null, "{$tahun}-04-01",
        );
    } catch (BusinessRuleException $e) {
        $terima = false;
    }
    $cek('input pada akun yang masih under DITERIMA', $terima);

    echo "\n=== 4. Saringan kolom ===\n";

    $nomorDari = function (array $param) use ($layar, $isi) {
        return $isi($layar($param))->pluck('journal_number')->sort()->values()->all();
    };
    $dasar = ['date_from' => $dari, 'date_to' => $sampai];

    $cek('saringan No. Jurnal', $nomorDari($dasar + ['f_number' => 'UJI/UNDER/1']) === ['UJI/UNDER/1']);
    $cek('saringan Akun menjaring jurnal yang menyentuh akun itu',
        $nomorDari($dasar + ['f_account' => (string) $akOver->id]) === ['UJI/OVER/1'],
        json_encode($nomorDari($dasar + ['f_account' => (string) $akOver->id])));
    $cek('saringan Keterangan Baris',
        $nomorDari($dasar + ['f_line_desc' => 'baris uji UJI/TANPA/1']) === ['UJI/TANPA/1'],
        json_encode($nomorDari($dasar + ['f_line_desc' => 'baris uji UJI/TANPA/1'])));
    $cek('saringan Debit', $nomorDari($dasar + ['f_debit' => '2000000']) === ['UJI/UNDER/1'],
        json_encode($nomorDari($dasar + ['f_debit' => '2000000'])));
    // Saringan "Baris (jumlah baris)" DIHAPUS bersama kolomnya; penggantinya saringan Cost Center.
    $cek('saringan Cost Center menjaring jurnal ber-cost-center itu',
        count($nomorDari($dasar + ['f_cost_center' => (string) $cc->id])) === 3,
        json_encode($nomorDari($dasar + ['f_cost_center' => (string) $cc->id])));
    $cek('saringan Tanggal', count($nomorDari($dasar + ['f_date' => "{$tahun}-03-10"])) === 3);
    $cek('saringan yang tidak cocok memulangkan kosong',
        $nomorDari($dasar + ['f_number' => 'TIDAK-ADA']) === []);

    // Pilihan tiap kolom memang terisi dari data pada rentang itu.
    $opsi = $layar($dasar)['opsiKolom'];
    foreach (['number', 'date', 'desc', 'cost_center', 'debit', 'credit', 'account', 'line_desc'] as $k) {
        $cek("pilihan kolom '{$k}' terisi", count($opsi[$k] ?? []) > 0, (string) count($opsi[$k] ?? []));
    }
    $cek('pilihan Akun memakai label kode — nama',
        (bool) collect($opsi['account'])->first(fn ($o) => str_contains($o['label'], ' — ')));

    // Membersihkan jurnal uji lebih dulu supaya rollback tidak menyisakan apa pun
    // seandainya transaksinya ternyata tidak jalan.
    foreach ([$jOver, $jUnder, $jTanpa] as $j) {
        $j->lines()->delete();
        $j->forceDelete();
    }
} finally {
    DB::rollBack();
}

echo "\n=== 5. Rollback bersih ===\n";
$cek('jurnal uji tidak tertinggal', BsJournal::where('journal_number', 'like', 'UJI/%')->count() === 0);
$cek('anggaran uji tidak tertinggal', BsBudget::where('doc_no', 'like', 'UJI-%')->count() === 0);

echo "\n==================================================\n";
echo "  LULUS {$lulus} · GAGAL {$gagal}\n";
echo "==================================================\n";

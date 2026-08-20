<?php

declare(strict_types=1);

/**
 * Uji: kolom Over/Under Budget + saringan kolom pada Detail Transaksi Jurnal.
 *
 * Jalankan:  docker exec ipro-fo-app php artisan tinker /var/www/html/tests/Manual/uji-jurnal-anggaran-kolom.php
 *
 * Yang diperiksa:
 *   1. Keterangan anggaran per jurnal: over (merah) / under (hijau) / tanpa anggaran.
 *   2. Hanya anggaran BERSTATUS DISETUJUI yang dipakai — sama dengan gerbang over-budget.
 *   3. Anggaran lintas cost center (cost_center_id kosong) berlaku untuk cost center mana pun.
 *   4. Gerbang over-budget benar-benar MENOLAK input pada akun yang sudah over, dan
 *      MENERIMA pada yang masih under — inilah yang dijanjikan keterangan di layar.
 *   5. Saringan kolom Anggaran memulangkan jurnal yang tepat untuk ketiga nilainya.
 *   6. Saringan kolom lain (No. Jurnal, Akun, Keterangan Baris) menyaring dengan benar,
 *      dan rekap per status ikut tersaring supaya angkanya tidak menghitung yang tak tampil.
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

    echo "\n=== 1. Keterangan anggaran per jurnal ===\n";

    $props = $layar(['date_from' => $dari, 'date_to' => $sampai]);
    $baris = $isi($props)->keyBy('journal_number');

    $cek('ketiga jurnal uji tampil', $baris->count() === 3, (string) $baris->count());
    $cek('jurnal yang melewati anggaran → over',
        ($baris['UJI/OVER/1']->budget_status ?? null) === 'over',
        (string) ($baris['UJI/OVER/1']->budget_status ?? 'null'));
    $cek('jurnal yang masih di bawah anggaran → under',
        ($baris['UJI/UNDER/1']->budget_status ?? null) === 'under',
        (string) ($baris['UJI/UNDER/1']->budget_status ?? 'null'));
    $cek('jurnal tanpa anggaran TIDAK disebut under',
        ($baris['UJI/TANPA/1']->budget_status ?? null) === 'none',
        (string) ($baris['UJI/TANPA/1']->budget_status ?? 'null'));

    // Keterangan over membawa angkanya sendiri, supaya tak perlu pindah layar.
    $catatan = (string) ($baris['UJI/OVER/1']->budget_note ?? '');
    $cek('keterangan over menyebut anggaran & realisasinya',
        str_contains($catatan, '1.000.000') && str_contains($catatan, '1.500.000'), $catatan);
    // Under Budget HARUS membawa angkanya juga. Kalau tidak, ia hanya berbeda warna dari
    // Tanpa Anggaran, padahal keduanya keadaan yang berbeda: yang satu punya anggaran dan
    // masih bersisa, yang satu memang tidak punya anggaran sama sekali.
    $catatanUnder = (string) ($baris['UJI/UNDER/1']->budget_note ?? '');
    $cek('keterangan under menyebut anggaran, realisasi, dan sisanya',
        str_contains($catatanUnder, '10.000.000')
        && str_contains($catatanUnder, '2.000.000')
        && str_contains($catatanUnder, '8.000.000'), $catatanUnder);
    $cek('jurnal tanpa anggaran TIDAK membawa angka apa pun',
        ($baris['UJI/TANPA/1']->budget_note ?? null) === null,
        (string) ($baris['UJI/TANPA/1']->budget_note ?? 'null'));
    $cek('ketiganya benar-benar berlainan',
        count(array_unique([
            $baris['UJI/OVER/1']->budget_status,
            $baris['UJI/UNDER/1']->budget_status,
            $baris['UJI/TANPA/1']->budget_status,
        ])) === 3);

    $cek('layar mengirim pilihan saringan anggaran',
        ($props['budgetStatuses'] ?? []) === JournalController::BUDGET_STATUSES,
        json_encode($props['budgetStatuses'] ?? null));

    echo "\n=== 1b. Under Budget menyebut anggaran yang paling sempit ===\n";

    // Satu jurnal bisa menyentuh beberapa anggaran sekaligus. Yang disebut harus anggaran
    // dengan SISA PALING SEDIKIT — anggaran itulah yang paling dulu menolak input berikutnya.
    $akSempit = BsAccount::where('is_postable', true)->where('type', 'expense')
        ->whereNotIn('id', [$akOver->id, $akUnder->id, $akTanpa->id])->orderBy('code')->first();
    if ($akSempit) {
        BsBudget::create(['doc_no' => 'UJI-SEMPIT', 'year' => $tahun, 'account_id' => $akSempit->id,
            'cost_center_id' => $cc->id, 'amount' => 2_100_000, 'status' => 'approved']);

        $lawan = BsAccount::where('is_postable', true)->where('type', 'liability')->first();
        $jDua = BsJournal::create([
            'cost_center_id' => $cc->id, 'journal_number' => 'UJI/DUA/1',
            'journal_date' => "{$tahun}-03-10", 'period_year' => $tahun, 'period_month' => 3,
            'source' => 'memorial', 'description' => 'UJI ANGGARAN DUA', 'status' => 'final',
            'total_debit' => 4_000_000, 'total_credit' => 4_000_000,
        ]);
        // Menyentuh dua akun beranggaran: sisa 8.000.000 dan sisa 100.000.
        $jDua->lines()->create(['account_id' => $akUnder->id, 'cost_center_id' => $cc->id, 'debit' => 0, 'credit' => 0, 'description' => 'sentuh anggaran longgar']);
        $jDua->lines()->create(['account_id' => $akSempit->id, 'cost_center_id' => $cc->id, 'debit' => 2_000_000, 'credit' => 0, 'description' => 'sentuh anggaran sempit']);
        $jDua->lines()->create(['account_id' => $lawan->id, 'debit' => 0, 'credit' => 2_000_000, 'description' => 'lawan']);

        $pDua = $layar(['date_from' => $dari, 'date_to' => $sampai]);
        $bDua = $isi($pDua)->keyBy('journal_number');
        $cek('jurnal yang menyentuh dua anggaran tetap under',
            ($bDua['UJI/DUA/1']->budget_status ?? null) === 'under',
            (string) ($bDua['UJI/DUA/1']->budget_status ?? 'null'));
        $cek('yang disebut adalah anggaran bersisa paling sedikit (100.000)',
            str_contains((string) ($bDua['UJI/DUA/1']->budget_note ?? ''), '100.000'),
            (string) ($bDua['UJI/DUA/1']->budget_note ?? ''));

        $jDua->lines()->delete();
        $jDua->forceDelete();
        BsBudget::where('doc_no', 'UJI-SEMPIT')->delete();
    } else {
        echo "  (dilewati — akun beban uji tidak cukup)\n";
    }

    echo "\n=== 2. Hanya anggaran disetujui yang dipakai ===\n";

    $draft = BsBudget::create(['doc_no' => 'UJI-DRAFT', 'year' => $tahun, 'account_id' => $akTanpa->id,
        'cost_center_id' => $cc->id, 'amount' => 1, 'status' => 'draft']);
    $p2 = $layar(['date_from' => $dari, 'date_to' => $sampai]);
    $b2 = $isi($p2)->keyBy('journal_number');
    $cek('anggaran draft tidak membuat jurnal jadi over',
        ($b2['UJI/TANPA/1']->budget_status ?? null) === 'none',
        (string) ($b2['UJI/TANPA/1']->budget_status ?? 'null'));

    $draft->update(['status' => 'approved']);
    $p3 = $layar(['date_from' => $dari, 'date_to' => $sampai]);
    $b3 = $isi($p3)->keyBy('journal_number');
    $cek('begitu anggaran disetujui, jurnalnya langsung terbaca over',
        ($b3['UJI/TANPA/1']->budget_status ?? null) === 'over',
        (string) ($b3['UJI/TANPA/1']->budget_status ?? 'null'));
    $draft->delete();

    echo "\n=== 3. Anggaran lintas cost center ===\n";

    if ($cc2) {
        $jLain = $buatJurnal('UJI/CC2/1', $akTanpa, (int) $cc2->id, 500_000);
        $global = BsBudget::create(['doc_no' => 'UJI-GLOBAL', 'year' => $tahun, 'account_id' => $akTanpa->id,
            'cost_center_id' => null, 'amount' => 100_000, 'status' => 'approved']);
        $p4 = $layar(['date_from' => $dari, 'date_to' => $sampai]);
        $b4 = $isi($p4)->keyBy('journal_number');
        $cek('anggaran tanpa cost center mengikat cost center mana pun',
            ($b4['UJI/CC2/1']->budget_status ?? null) === 'over',
            (string) ($b4['UJI/CC2/1']->budget_status ?? 'null'));
        $global->delete();
        $jLain->lines()->delete();
        $jLain->forceDelete();
    } else {
        echo "  (dilewati — hanya ada satu cost center di master)\n";
    }

    echo "\n=== 4. Gerbang input benar-benar menolak yang over ===\n";

    $gerbang = app(BudgetGuardService::class);
    $tolak = null;
    try {
        $gerbang->assertWithinBudget(
            [['account_id' => $akOver->id, 'cost_center_id' => $cc->id, 'debit' => 1000, 'credit' => 0]],
            null, "{$tahun}-04-01",
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
            null, "{$tahun}-04-01",
        );
    } catch (BusinessRuleException $e) {
        $terima = false;
    }
    $cek('input pada akun yang masih under DITERIMA', $terima);

    echo "\n=== 5. Saringan kolom Anggaran ===\n";

    $nomorDari = function (array $param) use ($layar, $isi) {
        return $isi($layar($param))->pluck('journal_number')->sort()->values()->all();
    };
    $dasar = ['date_from' => $dari, 'date_to' => $sampai];

    $cek('saringan "over" memulangkan hanya jurnal over',
        $nomorDari($dasar + ['f_budget' => 'over']) === ['UJI/OVER/1'],
        json_encode($nomorDari($dasar + ['f_budget' => 'over'])));
    $cek('saringan "under" memulangkan hanya jurnal under',
        $nomorDari($dasar + ['f_budget' => 'under']) === ['UJI/UNDER/1'],
        json_encode($nomorDari($dasar + ['f_budget' => 'under'])));
    $cek('saringan "tanpa anggaran" memulangkan hanya yang tak beranggaran',
        $nomorDari($dasar + ['f_budget' => 'none']) === ['UJI/TANPA/1'],
        json_encode($nomorDari($dasar + ['f_budget' => 'none'])));

    // Rekap ikut tersaring — angka yang tidak ikut tampil tidak boleh ikut dihitung.
    $rekapOver = $layar($dasar + ['f_budget' => 'over'])['jumlahStatus'];
    $cek('rekap ikut tersaring saringan anggaran',
        array_sum($rekapOver) === 1, json_encode($rekapOver));

    echo "\n=== 6. Saringan kolom lainnya ===\n";

    $cek('saringan No. Jurnal', $nomorDari($dasar + ['f_number' => 'UJI/UNDER/1']) === ['UJI/UNDER/1']);
    $cek('saringan Akun menjaring jurnal yang menyentuh akun itu',
        $nomorDari($dasar + ['f_account' => (string) $akOver->id]) === ['UJI/OVER/1'],
        json_encode($nomorDari($dasar + ['f_account' => (string) $akOver->id])));
    $cek('saringan Keterangan Baris',
        $nomorDari($dasar + ['f_line_desc' => 'baris uji UJI/TANPA/1']) === ['UJI/TANPA/1'],
        json_encode($nomorDari($dasar + ['f_line_desc' => 'baris uji UJI/TANPA/1'])));
    $cek('saringan Debit', $nomorDari($dasar + ['f_debit' => '2000000']) === ['UJI/UNDER/1'],
        json_encode($nomorDari($dasar + ['f_debit' => '2000000'])));
    $cek('saringan Baris (jumlah baris)',
        count($nomorDari($dasar + ['f_lines' => '2'])) === 3,
        json_encode($nomorDari($dasar + ['f_lines' => '2'])));
    $cek('saringan Tanggal', count($nomorDari($dasar + ['f_date' => "{$tahun}-03-10"])) === 3);
    $cek('saringan yang tidak cocok memulangkan kosong',
        $nomorDari($dasar + ['f_number' => 'TIDAK-ADA']) === []);

    // Pilihan tiap kolom memang terisi dari data pada rentang itu.
    $opsi = $layar($dasar)['opsiKolom'];
    foreach (['number', 'date', 'desc', 'lines', 'debit', 'credit', 'account', 'line_desc'] as $k) {
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

echo "\n=== 7. Rollback bersih ===\n";
$cek('jurnal uji tidak tertinggal', BsJournal::where('journal_number', 'like', 'UJI/%')->count() === 0);
$cek('anggaran uji tidak tertinggal', BsBudget::where('doc_no', 'like', 'UJI-%')->count() === 0);

echo "\n==================================================\n";
echo "  LULUS {$lulus} · GAGAL {$gagal}\n";
echo "==================================================\n";

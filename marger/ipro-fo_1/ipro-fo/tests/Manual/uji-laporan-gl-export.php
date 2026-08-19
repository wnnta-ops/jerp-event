<?php

declare(strict_types=1);

/**
 * Uji: export Excel + pilihan periode pada laporan GL.
 *
 * Jalankan:  docker exec ipro-fo-app php artisan tinker /var/www/html/tests/Manual/uji-laporan-gl-export.php
 *
 * Yang diperiksa:
 *   1. Neraca menghormati "Per Tanggal" — saldo kumulatif s/d tanggal itu, dan tanggal yang
 *      lebih awal tidak pernah memuat transaksi sesudahnya.
 *   2. Laba Rugi & Laba Rugi vs Budget menghormati rentang tanggal, dan rentang terbalik
 *      dibetulkan alih-alih memulangkan laporan kosong.
 *   3. Budget pada Laba Rugi vs Budget DIPRORATA ke bulan yang tercakup periodenya.
 *   4. Laporan GL Lanjutan: mode neraca memakai satu tanggal, mode laba rugi memakai rentang,
 *      dan keduanya berhenti pada tanggal yang dipilih.
 *   5. Kelima export memulangkan berkas .xlsx yang benar-benar bisa dibuka, berisi angka
 *      yang sama dengan yang tampil di layar.
 *
 * Semua yang menulis dibungkus transaksi lalu di-rollback.
 */

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsBudget;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Http\Controllers\Web\BusinessSupport\Gl\ComparativeReportController;
use App\Http\Controllers\Web\BusinessSupport\Gl\GlExtraReportController;
use App\Http\Controllers\Web\BusinessSupport\Gl\LedgerReportController;
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

$req = function (string $uri, array $param) use ($user) {
    $r = Illuminate\Http\Request::create($uri, 'GET', $param);
    $r->setUserResolver(fn () => $user);
    $r->setRouteResolver(fn () => new class
    {
        public function named(...$n)
        {
            return false;
        }
    });

    return $r;
};
/** Props Inertia dari sebuah Response. */
$props = fn ($resp, $r) => $resp->toResponse($r)->getOriginalContent()->getData()['page']['props'];

/** Baca berkas .xlsx hasil export menjadi teks sheet-nya. */
$sheet = function ($streamed): string {
    ob_start();
    $streamed->sendContent();
    $bin = (string) ob_get_clean();
    $tmp = tempnam(sys_get_temp_dir(), 'uji').'.xlsx';
    file_put_contents($tmp, $bin);
    $z = new ZipArchive();
    if ($z->open($tmp) !== true) {
        @unlink($tmp);

        return '';
    }
    $xml = (string) $z->getFromName('xl/worksheets/sheet1.xml');
    $z->close();
    @unlink($tmp);

    return $xml;
};

$ledger = app(LedgerReportController::class);
$lajur = app(ComparativeReportController::class);
$extra = app(GlExtraReportController::class);

DB::beginTransaction();
try {
    $tahun = 2032;
    $cc = BsCostCenter::first();
    $akunBeban = BsAccount::where('is_postable', true)->where('type', 'expense')->orderBy('code')->first();
    $akunKas   = BsAccount::where('is_postable', true)->where('type', 'asset')->orderBy('code')->first();
    $akunPend  = BsAccount::where('is_postable', true)->where('type', 'income')->orderBy('code')->first();
    $cek('akun uji tersedia', $akunBeban && $akunKas && $akunPend);

    // Tiga jurnal posted pada tiga bulan berbeda, supaya batas tanggal benar-benar teruji.
    $buat = function (string $no, string $tgl, $akD, $akK, float $nilai) use ($tahun, $cc) {
        $j = BsJournal::create([
            'cost_center_id' => $cc?->id, 'journal_number' => $no, 'journal_date' => $tgl,
            'period_year' => $tahun, 'period_month' => (int) substr($tgl, 5, 2),
            'source' => 'memorial', 'description' => 'UJI LAPORAN '.$no, 'status' => 'posted',
            'total_debit' => $nilai, 'total_credit' => $nilai,
        ]);
        $j->lines()->create(['account_id' => $akD->id, 'cost_center_id' => $cc?->id, 'debit' => $nilai, 'credit' => 0]);
        $j->lines()->create(['account_id' => $akK->id, 'cost_center_id' => $cc?->id, 'debit' => 0, 'credit' => $nilai]);

        return $j;
    };
    $buat('UJI/LAP/1', "{$tahun}-02-10", $akunBeban, $akunKas, 1_000_000);   // Feb
    $buat('UJI/LAP/2', "{$tahun}-05-10", $akunBeban, $akunKas, 2_000_000);   // Mei
    $buat('UJI/LAP/3', "{$tahun}-08-10", $akunKas, $akunPend, 9_000_000);    // Agu

    echo "\n=== 1. Neraca menghormati Per Tanggal ===\n";

    $neracaPada = function (string $tgl) use ($ledger, $req, $props) {
        $r = $req('/business-support/gl/neraca', ['as_of' => $tgl]);

        return $props($ledger->balanceSheet($r), $r);
    };

    $mar = $neracaPada("{$tahun}-03-31");
    $jun = $neracaPada("{$tahun}-06-30");
    $des = $neracaPada("{$tahun}-12-31");

    $cek('judulnya menyebut tanggal yang dipilih', str_contains((string) $mar['as_of'], 'Maret'), (string) $mar['as_of']);
    $cek('tanggal dikembalikan ke layar untuk dipakai lagi', $mar['as_of_date'] === "{$tahun}-03-31", (string) $mar['as_of_date']);

    // Kas berkurang 2 juta di Mei (beban dibayar kas) lalu bertambah 9 juta di Agustus.
    // Selisih antar tanggal harus PERSIS sebesar transaksi di antaranya — itulah bukti
    // batas tanggalnya benar-benar mengikat, bukan sekadar judulnya yang berubah.
    $cek('Juni = Maret − 2 juta (transaksi Mei masuk)',
        abs(((float) $jun['total_assets'] - (float) $mar['total_assets']) + 2_000_000) < 1,
        $mar['total_assets'].' → '.$jun['total_assets']);
    $cek('Desember = Juni + 9 juta (transaksi Agustus masuk)',
        abs(((float) $des['total_assets'] - (float) $jun['total_assets']) - 9_000_000) < 1,
        $jun['total_assets'].' → '.$des['total_assets']);
    $cek('neraca tetap balance di setiap tanggal',
        abs($mar['total_assets'] - $mar['total_passiva']) < 1
        && abs($des['total_assets'] - $des['total_passiva']) < 1,
        abs($des['total_assets'] - $des['total_passiva']).'');

    echo "\n=== 2. Laba Rugi menghormati rentang ===\n";

    $lrPada = function (string $dari, string $sampai) use ($ledger, $req, $props) {
        $r = $req('/business-support/gl/laba-rugi', ['date_from' => $dari, 'date_to' => $sampai]);

        return $props($ledger->incomeStatement($r), $r);
    };

    $q1 = $lrPada("{$tahun}-01-01", "{$tahun}-03-31");
    $sem1 = $lrPada("{$tahun}-01-01", "{$tahun}-06-30");
    $setahun = $lrPada("{$tahun}-01-01", "{$tahun}-12-31");

    $bebanQ1 = collect($q1['rows'])->firstWhere('label', 'JUMLAH BEBAN PENJUALAN & BUA')['amount'] ?? null;
    $cek('periode dikembalikan ke layar', $q1['date_from'] === "{$tahun}-01-01" && $q1['date_to'] === "{$tahun}-03-31");
    $cek('judul periodenya terbaca', str_contains((string) $q1['periode'], 's/d'), (string) $q1['periode']);
    $cek('rugi Q1 lebih kecil daripada semester 1 (transaksi Mei belum masuk Q1)',
        abs((float) $q1['net_profit']) < abs((float) $sem1['net_profit']) + 0.01,
        $q1['net_profit'].' vs '.$sem1['net_profit']);
    $cek('setahun memuat pendapatan Agustus, semester 1 tidak',
        (float) $setahun['net_profit'] > (float) $sem1['net_profit'],
        $sem1['net_profit'].' → '.$setahun['net_profit']);

    // Rentang terbalik dibetulkan, bukan memulangkan laporan kosong.
    $terbalik = $lrPada("{$tahun}-12-31", "{$tahun}-01-01");
    $cek('rentang terbalik dibetulkan',
        $terbalik['date_from'] === "{$tahun}-01-01" && $terbalik['date_to'] === "{$tahun}-12-31",
        $terbalik['date_from'].' s/d '.$terbalik['date_to']);
    $cek('hasilnya sama dengan rentang yang benar',
        abs((float) $terbalik['net_profit'] - (float) $setahun['net_profit']) < 0.01);

    echo "\n=== 3. Laba Rugi vs Budget — budget diprorata ===\n";

    BsBudget::create(['doc_no' => 'UJI-LAP-BUD', 'year' => $tahun, 'account_id' => $akunBeban->id,
        'cost_center_id' => $cc?->id, 'amount' => 12_000_000, 'status' => 'approved']);

    $vbPada = function (string $dari, string $sampai) use ($ledger, $req, $props) {
        $r = $req('/business-support/gl/laba-rugi-vs-budget', ['date_from' => $dari, 'date_to' => $sampai]);

        return $props($ledger->incomeVsBudget($r), $r);
    };
    $vbTahun = $vbPada("{$tahun}-01-01", "{$tahun}-12-31");
    $vbQ1    = $vbPada("{$tahun}-01-01", "{$tahun}-03-31");

    // Dibaca dari TOTALNYA, bukan dari salah satu baris: akun beban uji bisa jatuh ke
    // kelompok mana pun (HPP / beban penjualan / BUA) tergantung prefiks kodenya, dan yang
    // sedang diuji adalah proratanya — bukan pengelompokannya.
    $budgetOf = fn ($d) => abs((float) $d['totals']['budget']);
    $bTahun = $budgetOf($vbTahun);
    $bQ1 = $budgetOf($vbQ1);

    $cek('budget setahun = 12 juta', abs($bTahun - 12_000_000) < 1, (string) $bTahun);
    $cek('budget Q1 = seperempatnya (3 juta), bukan setahun penuh',
        abs($bQ1 - 3_000_000) < 1, (string) $bQ1);
    $cek('layar mengatakan budgetnya diprorata',
        str_contains((string) $vbQ1['budget_note'], 'diprorata'), (string) $vbQ1['budget_note']);
    $cek('setahun penuh TIDAK disebut diprorata',
        ! str_contains((string) $vbTahun['budget_note'], 'diprorata'), (string) $vbTahun['budget_note']);

    echo "\n=== 4. Laporan GL Lanjutan ===\n";

    $extraPada = function (array $p) use ($extra, $req, $props) {
        $r = $req('/business-support/gl/laporan-lanjutan', $p);

        return $props($extra->index($r), $r);
    };

    $multiAgu = $extraPada(['mode' => 'multi', 'as_of' => "{$tahun}-08-20"]);
    $cek('mode neraca ditandai jenis "neraca"', ($multiAgu['jenis'] ?? '') === 'neraca', (string) ($multiAgu['jenis'] ?? ''));
    $cek('Neraca Multi Periode berhenti di bulan tanggalnya (8 kolom)',
        count($multiAgu['columns']) === 8, (string) count($multiAgu['columns']));
    $cek('kolom terakhir menandai tanggal potongnya',
        str_contains(end($multiAgu['columns']), '(20)'), (string) end($multiAgu['columns']));

    $tahunanAgu = $extraPada(['mode' => 'tahunan', 'as_of' => "{$tahun}-08-20"]);
    // Nama bulannya mengikuti locale (Carbon menulis "Agt"), jadi yang diperiksa tanggal &
    // tahunnya — bukan ejaan bulannya.
    $cek('Neraca Komparasi membandingkan tanggal yang setara',
        substr_count($tahunanAgu['title'], '20 ') >= 2
        && str_contains($tahunanAgu['title'], (string) $tahun)
        && str_contains($tahunanAgu['title'], (string) ($tahun - 1)),
        $tahunanAgu['title']);

    $lrMulti = $extraPada(['mode' => 'lr-multi', 'date_from' => "{$tahun}-02-01", 'date_to' => "{$tahun}-05-31"]);
    $cek('mode laba rugi ditandai jenis "laba_rugi"', ($lrMulti['jenis'] ?? '') === 'laba_rugi', (string) ($lrMulti['jenis'] ?? ''));
    $cek('Laba Rugi Multi Periode hanya menampilkan bulan yang tersentuh (4 kolom)',
        count($lrMulti['columns']) === 4, (string) count($lrMulti['columns']).' → '.implode(',', $lrMulti['columns']));

    $lrBanding = $extraPada(['mode' => 'lr-banding', 'date_from' => "{$tahun}-01-01", 'date_to' => "{$tahun}-06-30"]);
    $cek('Laba Rugi Perbandingan memakai 3 periode setara',
        $lrBanding['columns'] === [(string) ($tahun - 2), (string) ($tahun - 1), (string) $tahun],
        implode(',', $lrBanding['columns']));

    echo "\n=== 5. Export Excel ===\n";

    $ujiExport = function (string $nama, $streamed, array $harusAda) use ($sheet, $cek) {
        $xml = $sheet($streamed);
        $cek($nama.': berkas xlsx terbaca', $xml !== '' && str_contains($xml, '<sheetData>'));
        foreach ($harusAda as $teks) {
            $cek($nama.': memuat "'.$teks.'"', str_contains($xml, $teks));
        }
    };

    $rNeraca = $req('/business-support/gl/neraca/export', ['as_of' => "{$tahun}-12-31"]);
    $ujiExport('Neraca', $ledger->balanceSheetExport($rNeraca), ['NERACA', 'JUMLAH AKTIVA', 'JUMLAH PASSIVA']);

    $rLr = $req('/business-support/gl/laba-rugi/export', ['date_from' => "{$tahun}-01-01", 'date_to' => "{$tahun}-12-31"]);
    $ujiExport('Laba Rugi', $ledger->incomeStatementExport($rLr), ['LAPORAN LABA RUGI', 'LABA RUGI NETTO']);

    $rVb = $req('/business-support/gl/laba-rugi-vs-budget/export', ['date_from' => "{$tahun}-01-01", 'date_to' => "{$tahun}-03-31"]);
    $ujiExport('LR vs Budget', $ledger->incomeVsBudgetExport($rVb), ['LABA RUGI VS BUDGET', 'Variance', 'diprorata']);

    $rLajur = $req('/business-support/gl/neraca-lajur/export', ['year' => $tahun]);
    $ujiExport('Neraca Lajur', $lajur->worksheetExport($rLajur), ['NERACA LAJUR', 'Neraca Saldo', 'LABA (RUGI) BERSIH']);

    $rExtra = $req('/business-support/gl/laporan-lanjutan/export', ['mode' => 'lr-tahunan', 'date_from' => "{$tahun}-01-01", 'date_to' => "{$tahun}-12-31"]);
    $ujiExport('GL Lanjutan', $extra->export($rExtra), ['Laba Rugi Komparasi Tahunan', 'Selisih']);

    // Angka di Excel harus sama dengan angka di layar — bukan sekadar ada isinya.
    $xmlNeraca = $sheet($ledger->balanceSheetExport($req('/business-support/gl/neraca/export', ['as_of' => "{$tahun}-12-31"])));
    $aset = (float) $des['total_assets'];
    $cek('angka Neraca di Excel sama dengan di layar',
        str_contains($xmlNeraca, '<v>'.rtrim(rtrim(sprintf('%.4f', $aset), '0'), '.').'</v>'),
        'aset layar = '.$aset);
} finally {
    DB::rollBack();
}

echo "\n=== 6. Rollback bersih ===\n";
$cek('jurnal uji tidak tertinggal', BsJournal::where('journal_number', 'like', 'UJI/LAP/%')->count() === 0);
$cek('anggaran uji tidak tertinggal', BsBudget::where('doc_no', 'UJI-LAP-BUD')->count() === 0);

echo "\n==================================================\n";
echo "  LULUS {$lulus} · GAGAL {$gagal}\n";
echo "==================================================\n";

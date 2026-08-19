<?php
// Uji dua query baru di JournalController@index yang hanya bisa dijalankan dengan database:
//   · penanda period_closed per baris;
//   · rekap jumlah per status, dengan aturan yang sama seperti General Function.
// Dibungkus transaksi lalu di-rollback.

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Http\Controllers\Web\BusinessSupport\Gl\JournalController;
use App\Http\Controllers\Web\BusinessSupport\Gl\GeneralLedgerController;

$lolos = 0;
$gagal = 0;
$ok = function (bool $c, string $n) use (&$lolos, &$gagal) {
    $c ? $lolos++ : $gagal++;
    echo ($c ? '  OK    ' : '  GAGAL ').$n."\n";
};
$isi = function (string $tabel, array $timpa = []) {
    $kolom = [];
    $isian = [];
    foreach (DB::select('SHOW COLUMNS FROM '.$tabel) as $k) {
        $kolom[$k->Field] = true;
        if ($k->Null === 'NO' && $k->Default === null && $k->Extra !== 'auto_increment') {
            $isian[$k->Field] = str_contains($k->Type, 'int') || str_contains($k->Type, 'decimal') ? 0
                : (str_contains($k->Type, 'date') || str_contains($k->Type, 'time') ? now() : 'UJI');
        }
    }

    return array_merge($isian, array_intersect_key($timpa, $kolom));
};

DB::beginTransaction();
try {
    $user   = App\Models\User::first();
    $tenant = (int) (DB::table('bs_journals')->value('tenant_id') ?? 1);
    $akun1  = DB::table('bs_accounts')->value('id');
    $akun2  = DB::table('bs_accounts')->where('id', '!=', $akun1)->value('id');
    $THN    = 2033;   // tahun yang dipastikan kosong, supaya angkanya bisa diramal

    $sisa = DB::table('bs_journals')->where('period_year', $THN)->count();
    $ok($sisa === 0, "tahun {$THN} memang kosong sebelum uji (sisa {$sisa})");

    // Periode 5 DITUTUP, periode 6 terbuka.
    foreach ([[5, 'closed'], [6, 'open']] as [$bln, $st]) {
        DB::table('bs_fiscal_periods')->insert($isi('bs_fiscal_periods', [
            'tenant_id' => $tenant, 'year' => $THN, 'month' => $bln, 'status' => $st,
            'closed_at' => $st === 'closed' ? now() : null, 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    $urut = 0;
    $buat = function (int $bln, string $status) use ($isi, $tenant, $akun1, $akun2, $THN, &$urut) {
        $urut++;
        $tgl = sprintf('%04d-%02d-15', $THN, $bln);
        $id = DB::table('bs_journals')->insertGetId($isi('bs_journals', [
            'tenant_id' => $tenant, 'journal_number' => 'JU/UJI/'.$THN.'/'.$urut, 'source' => 'memorial',
            'journal_date' => $tgl, 'period_year' => $THN, 'period_month' => $bln, 'status' => $status,
            'description' => 'Uji rekap', 'total_debit' => 1000, 'total_credit' => 1000,
            'created_at' => now(), 'updated_at' => now(),
        ]));
        DB::table('bs_journal_lines')->insert([
            $isi('bs_journal_lines', ['journal_id' => $id, 'account_id' => $akun1, 'debit' => 1000, 'credit' => 0, 'created_at' => now(), 'updated_at' => now()]),
            $isi('bs_journal_lines', ['journal_id' => $id, 'account_id' => $akun2, 'debit' => 0, 'credit' => 1000, 'created_at' => now(), 'updated_at' => now()]),
        ]);

        return $id;
    };

    // Bulan 5 (TERTUTUP): 1 draft + 1 posted → keduanya harus masuk kolom Closed.
    $tutup1 = $buat(5, 'draft');
    $tutup2 = $buat(5, 'posted');
    // Bulan 6 (terbuka): 1 draft, 2 final, 1 posted.
    $buka1 = $buat(6, 'draft');
    $buat(6, 'final');
    $buat(6, 'final');
    $buat(6, 'posted');

    // Urutannya penting: menukar instance 'request' di container memicu rebinding Laravel
    // yang MENIMPA user-resolver dengan resolver guard (kosong di lingkungan tinker), jadi
    // resolvernya dipasang SESUDAH penukaran — bukan sebelum.
    $siapkan = function (array $q) use ($user) {
        $req = Request::create('/x', 'GET', $q, [], [], ['HTTP_X_INERTIA' => 'true', 'HTTP_X_INERTIA_VERSION' => '1']);
        app()->instance('request', $req);
        $req->setUserResolver(fn () => $user);

        return $req;
    };
    $props = function (array $q) use ($siapkan) {
        $req = $siapkan($q);

        return app(JournalController::class)->index($req)->toResponse($req)->getData(true)['props'];
    };

    $p = $props(['date_from' => "{$THN}-05-01", 'date_to' => "{$THN}-06-30"]);

    echo "\n══ Rekap per status ══\n";
    $j = $p['jumlahStatus'] ?? null;
    $ok($j !== null, 'controller mengirim jumlahStatus');
    $ok(($j['closed'] ?? -1) === 2, '  Closed = 2 (kedua jurnal bulan tertutup, apa pun statusnya): '.($j['closed'] ?? '-'));
    $ok(($j['baru'] ?? -1) === 1, '  Baru = 1 (yang di bulan tertutup TIDAK ikut dihitung): '.($j['baru'] ?? '-'));
    $ok(($j['final'] ?? -1) === 2, '  Final = 2: '.($j['final'] ?? '-'));
    $ok(($j['approved'] ?? -1) === 1, '  Approved = 1: '.($j['approved'] ?? '-'));
    $ok(array_sum($j) === 6, '  totalnya 6 jurnal, tidak ada yang terhitung dua kali');
    $ok(($p['tahunRekap'] ?? null) === $THN, '  tahunRekap untuk tautan General Function: '.($p['tahunRekap'] ?? '-'));

    echo "\n══ Sama dengan General Function ══\n";
    $reqGf = $siapkan(['year' => $THN]);
    $gf = app(GeneralLedgerController::class)->generalFunction($reqGf)->toResponse($reqGf)->getData(true)['props']['totals'];
    $ok($gf == $j, 'keempat angkanya SAMA PERSIS dengan General Function '.$THN.': GF='.json_encode($gf).' vs Jurnal='.json_encode($j));

    echo "\n══ Penanda periode tertutup per baris ══\n";
    $baris = collect($p['journals']['data'] ?? [])->keyBy('id');
    $ok($baris->count() === 6, 'keenam jurnal tampil di daftar: '.$baris->count());
    $ok(($baris[$tutup1]['period_closed'] ?? null) === true, '  jurnal bulan 5 (closed) bertanda terkunci');
    $ok(($baris[$tutup2]['period_closed'] ?? null) === true, '  jurnal bulan 5 kedua juga terkunci');
    $ok(($baris[$buka1]['period_closed'] ?? null) === false, '  jurnal bulan 6 (open) TIDAK terkunci');

    echo "\n══ Rekap mengabaikan filter Status, tapi menghormati filter lain ══\n";
    $pFilter = $props(['date_from' => "{$THN}-05-01", 'date_to' => "{$THN}-06-30", 'status' => 'final']);
    $ok($pFilter['jumlahStatus'] == $j, 'disaring ke Final: keempat angka rekap TETAP utuh '.json_encode($pFilter['jumlahStatus']));
    $ok(count($pFilter['journals']['data']) === 2, '  tapi daftarnya menyusut jadi 2 baris Final');

    $pBulan6 = $props(['date_from' => "{$THN}-06-01", 'date_to' => "{$THN}-06-30"]);
    $ok(($pBulan6['jumlahStatus']['closed'] ?? -1) === 0,
        'rentang hanya bulan terbuka → Closed = 0: '.($pBulan6['jumlahStatus']['closed'] ?? '-'));
    $ok(($pBulan6['jumlahStatus']['baru'] ?? -1) === 1, '  Baru tetap 1');

    $pCari = $props(['date_from' => "{$THN}-05-01", 'date_to' => "{$THN}-06-30", 'search' => 'JU/UJI/'.$THN.'/1']);
    $ok(array_sum($pCari['jumlahStatus']) === 1, 'filter pencarian ikut menyempitkan rekap: '.json_encode($pCari['jumlahStatus']));

    echo "\n{$lolos} lolos, {$gagal} gagal\n";
} catch (\Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n";
    $gagal++;
} finally {
    DB::rollBack();
    echo "(transaksi di-rollback — tidak ada data tersisa)\n";
}
exit($gagal ? 1 : 0);

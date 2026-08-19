<?php
// Uji rantai penuh: Cost Center pada Realisasi Bon Sementara → jurnal → Budget Control.
//
// Yang dibuktikan di sini bukan sekadar "kolomnya wajib", melainkan bahwa cost center yang
// diisi operator benar-benar sampai ke bs_journal_lines dan terbaca sebagai realisasi
// anggaran pada (akun, cost center) yang tepat — dan bahwa realisasi TANPA cost center
// ditolak server, bukan lolos diam-diam lalu hilang dari Budget Control.
//
// Dibungkus transaksi lalu di-rollback: tidak meninggalkan satu baris pun di database.

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Domain\BusinessSupport\Models\BsCashAdvance;
use App\Domain\BusinessSupport\Services\BudgetRealizationService;
use App\Domain\BusinessSupport\Services\CashAdvanceService;
use App\Http\Controllers\Web\BusinessSupport\CashBook\CashAdvanceController;

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
    $tenant = (int) (DB::table('bs_cash_advances')->value('tenant_id') ?? 1);
    $uniq   = substr((string) microtime(true), -6);
    $tahun  = (int) now()->year;

    $akunBeban = DB::table('bs_accounts')->where('type', 'expense')->where('is_postable', true)->value('id');
    $akunKas   = DB::table('bs_accounts')->where('code', 'like', '101010%')->value('id') ?: DB::table('bs_accounts')->value('id');
    $ok((bool) $akunBeban && (bool) $akunKas, 'akun beban & kas tersedia untuk uji');

    // Dua cost center: satu yang dipakai, satu pembanding yang TIDAK boleh ikut terisi.
    $ccPakai = DB::table('bs_cost_centers')->insertGetId($isi('bs_cost_centers', [
        'tenant_id' => $tenant, 'code' => 'CCA'.$uniq, 'name' => 'CC UJI DIPAKAI',
        'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]));
    $ccLain = DB::table('bs_cost_centers')->insertGetId($isi('bs_cost_centers', [
        'tenant_id' => $tenant, 'code' => 'CCB'.$uniq, 'name' => 'CC UJI PEMBANDING',
        'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]));

    $realisasi = app(BudgetRealizationService::class);
    $awalPakai = $realisasi->total($tahun, $akunBeban, $ccPakai);
    $awalLain  = $realisasi->total($tahun, $akunBeban, $ccLain);

    $buatBon = fn (string $no) => DB::table('bs_cash_advances')->insertGetId($isi('bs_cash_advances', [
        'tenant_id' => $tenant, 'advance_number' => $no, 'advance_date' => now()->toDateString(),
        'holder' => 'Pemegang Uji', 'purpose' => 'Uji realisasi', 'cash_account_id' => $akunKas,
        'amount' => 500000, 'status' => 'outstanding', 'created_by' => $user?->id,
        'created_at' => now(), 'updated_at' => now(),
    ]));

    $req = function (array $body) use ($user) {
        $r = Request::create('/x', 'POST', $body);
        app()->instance('request', $r);
        // Resolver dipasang SESUDAH penukaran instance — lihat catatan di README.
        $r->setUserResolver(fn () => $user);

        return $r;
    };

    echo "\n══ Realisasi DENGAN cost center ══\n";
    $bon = BsCashAdvance::findOrFail($buatBon('BONCC'.$uniq));
    app(CashAdvanceController::class)->realize(
        $req(['realized_date' => now()->toDateString(), 'lines' => [[
            'expense_account_id' => $akunBeban, 'cost_center_id' => $ccPakai,
            'description' => 'Beli ATK uji', 'amount' => 300000,
        ]]]),
        $bon,
        app(CashAdvanceService::class),
    );
    $bon->refresh();
    $ok($bon->status === 'realized', 'bon berubah menjadi realized');
    $ok((bool) $bon->realize_journal_id, '  jurnal realisasi terbentuk: #'.$bon->realize_journal_id);

    $baris = DB::table('bs_journal_lines')->where('journal_id', $bon->realize_journal_id)
        ->where('account_id', $akunBeban)->first();
    $ok($baris !== null, '  baris jurnal beban ada');
    $ok((int) ($baris->cost_center_id ?? 0) === $ccPakai,
        '  cost center IKUT tersimpan di bs_journal_lines: '.($baris->cost_center_id ?? 'null'));

    echo "\n══ Terbaca sebagai realisasi budget ══\n";
    $sesudahPakai = $realisasi->total($tahun, $akunBeban, $ccPakai);
    $sesudahLain  = $realisasi->total($tahun, $akunBeban, $ccLain);
    $ok(round($sesudahPakai - $awalPakai, 2) === 300000.0,
        'realisasi cost center yang dipilih bertambah 300.000: '.number_format($sesudahPakai - $awalPakai, 2));
    $ok(round($sesudahLain - $awalLain, 2) === 0.0,
        '  cost center lain TIDAK ikut bertambah: '.number_format($sesudahLain - $awalLain, 2));

    $perBulan = $realisasi->byMonth($tahun, $akunBeban, $ccPakai);
    $ok(($perBulan[(int) now()->month] ?? 0) >= 300000.0,
        '  muncul pada bulan berjalan di rincian per bulan: '.number_format($perBulan[(int) now()->month] ?? 0, 2));

    echo "\n══ Realisasi TANPA cost center ditolak server ══\n";
    $bon2 = BsCashAdvance::findOrFail($buatBon('BONNO'.$uniq));
    $tolak = null;
    try {
        app(CashAdvanceController::class)->realize(
            $req(['realized_date' => now()->toDateString(), 'lines' => [[
                'expense_account_id' => $akunBeban, 'description' => 'Tanpa cost center', 'amount' => 100000,
            ]]]),
            $bon2,
            app(CashAdvanceService::class),
        );
    } catch (ValidationException $e) {
        $tolak = $e;
    }
    $ok($tolak !== null, 'ditolak, bukan diam-diam dikerjakan');
    $ok($tolak && array_key_exists('lines.0.cost_center_id', $tolak->errors()),
        '  galatnya menunjuk kolom cost center: '.implode(', ', array_keys($tolak?->errors() ?? [])));
    $ok(BsCashAdvance::find($bon2->id)->status === 'outstanding',
        '  bonnya TIDAK berubah status: '.BsCashAdvance::find($bon2->id)->status);
    $ok(round($realisasi->total($tahun, $akunBeban, $ccPakai) - $sesudahPakai, 2) === 0.0,
        '  tidak ada realisasi anggaran yang terbentuk dari percobaan itu');

    echo "\n══ Cost center yang tidak terdaftar juga ditolak ══\n";
    $bon3 = BsCashAdvance::findOrFail($buatBon('BONXX'.$uniq));
    $tolak2 = null;
    try {
        app(CashAdvanceController::class)->realize(
            $req(['realized_date' => now()->toDateString(), 'lines' => [[
                'expense_account_id' => $akunBeban, 'cost_center_id' => 999999999,
                'description' => 'Cost center karangan', 'amount' => 100000,
            ]]]),
            $bon3,
            app(CashAdvanceService::class),
        );
    } catch (ValidationException $e) {
        $tolak2 = $e;
    }
    $ok($tolak2 !== null && array_key_exists('lines.0.cost_center_id', $tolak2->errors()),
        'id cost center yang tidak ada di master ditolak (aturan exists)');

    echo "\n{$lolos} lolos, {$gagal} gagal\n";
} catch (\Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n";
    $gagal++;
} finally {
    DB::rollBack();
    echo "(transaksi di-rollback — tidak ada data tersisa)\n";
}
exit($gagal ? 1 : 0);

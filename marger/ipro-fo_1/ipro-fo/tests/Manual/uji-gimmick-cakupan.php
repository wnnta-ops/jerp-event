<?php
// Uji cakupan Rincian Biaya pada Setup Kategori Gimmick.
//
// Yang dibuktikan: sebuah kolom hilang dari daftar "belum tertaut" begitu ADA kategori yang
// menautkannya — tanpa menunggu sakelar sinkronnya dihidupkan — sementara keadaan sinkron
// tiap kategori tetap dilaporkan terpisah lewat `aktif`, supaya layar masih bisa
// membedakan "sudah dipegang" dari "sudah akan terisi".
//
// Dibungkus transaksi lalu di-rollback: tidak meninggalkan satu baris pun di database.

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Domain\BusinessSupport\Models\BsGimmickCategory;
use App\Http\Controllers\Web\BusinessSupport\Payable\GimmickCategoryController;

$lolos = 0;
$gagal = 0;
$ok = function (bool $c, string $n) use (&$lolos, &$gagal) {
    $c ? $lolos++ : $gagal++;
    echo ($c ? '  OK    ' : '  GAGAL ').$n."\n";
};

DB::beginTransaction();
try {
    $user = App\Models\User::find(1);
    $ctrl = app(GimmickCategoryController::class);

    $cakupan = function () use ($user, $ctrl) {
        $r = Request::create('/x', 'GET', [], [], [], ['HTTP_X_INERTIA' => 'true', 'HTTP_X_INERTIA_VERSION' => '1']);
        app()->instance('request', $r);
        $r->setUserResolver(fn () => $user);   // resolver SESUDAH penukaran instance

        return collect($ctrl->index($r)->toResponse($r)->getData(true)['props']['kprBiayaCoverage'])->keyBy('key');
    };
    $belumTertaut = fn ($c) => $c->filter(fn ($x) => ! count($x['categories']))->keys()->all();

    // Bersihkan tautan yang ada supaya keadaan awalnya bisa diramal (di dalam transaksi).
    BsGimmickCategory::query()->update(['kpr_biaya_key' => null, 'sync_kpr' => false]);
    $kolom = array_keys(BsGimmickCategory::KPR_BIAYA_COLUMNS);
    $jml   = count($kolom);

    $c = $cakupan();
    $ok(count($belumTertaut($c)) === $jml, "keadaan awal: seluruh {$jml} kolom belum tertaut");

    $kat = BsGimmickCategory::first();
    $ok($kat !== null, 'ada kategori gimmick untuk diuji: '.$kat?->code);

    echo "\n══ Tertaut TAPI sinkron mati ══\n";
    $kat->update(['kpr_biaya_key' => $kolom[0], 'sync_kpr' => false]);
    $c = $cakupan();
    $ok(! in_array($kolom[0], $belumTertaut($c), true),
        "kolom {$kolom[0]} HILANG dari daftar belum-tertaut walau sinkronnya mati");
    $ok(count($belumTertaut($c)) === $jml - 1, '  sisanya berkurang satu: '.count($belumTertaut($c)));
    $ok($c[$kolom[0]]['categories'] === [$kat->code], '  kategorinya tercatat: '.implode(',', $c[$kolom[0]]['categories']));
    $ok((int) $c[$kolom[0]]['aktif'] === 0,
        '  TAPI aktif = 0 — layar tetap tahu kolom ini belum akan terisi: '.$c[$kolom[0]]['aktif']);

    echo "\n══ Sinkron dihidupkan ══\n";
    $kat->update(['sync_kpr' => true]);
    $c = $cakupan();
    $ok(! in_array($kolom[0], $belumTertaut($c), true), 'kolomnya tetap hilang dari daftar');
    $ok((int) $c[$kolom[0]]['aktif'] === 1, '  aktif naik jadi 1: '.$c[$kolom[0]]['aktif']);

    echo "\n══ Tautan dilepas ══\n";
    $kat->update(['kpr_biaya_key' => null, 'sync_kpr' => false]);
    $c = $cakupan();
    $ok(in_array($kolom[0], $belumTertaut($c), true), 'kolomnya MUNCUL LAGI di daftar belum-tertaut');
    $ok(count($belumTertaut($c)) === $jml, '  kembali '.$jml.' kolom belum tertaut');

    echo "\n══ Seluruh kolom tertaut → daftar kosong ══\n";
    $semua = BsGimmickCategory::orderBy('id')->take($jml)->get();
    if ($semua->count() < $jml) {
        $ok(false, 'butuh minimal '.$jml.' kategori untuk menguji ini, yang ada '.$semua->count());
    } else {
        foreach ($semua as $i => $k) {
            $k->update(['kpr_biaya_key' => $kolom[$i], 'sync_kpr' => $i % 2 === 0]);
        }
        $c = $cakupan();
        $ok($belumTertaut($c) === [], 'tidak ada lagi kolom yang belum tertaut → panel cakupan hilang di layar');
        $aktif = collect($c)->sum('aktif');
        $ok($aktif > 0 && $aktif < $jml,
            '  sebagian sinkronnya mati dan itu TETAP terlihat lewat aktif: '.$aktif.' dari '.$jml);
    }

    echo "\n══ Nama kolom & penanda pada layar ══\n";
    $vue = file_get_contents(base_path('resources/js/Pages/BusinessSupport/Payable/GimmickCategories.vue'));
    $ok(str_contains($vue, '>Sinkronisasi Gimmick</th>'), 'judul kolom kini "Sinkronisasi Gimmick"');
    $ok(! str_contains($vue, 'Kolom Biaya KPR</th>'), '  judul lama "Kolom Biaya KPR" sudah tidak ada');
    $ok(str_contains($vue, 'v-if="belumTertaut.length"'), 'panel cakupan hanya tampil bila masih ada yang belum tertaut');
    $ok(str_contains($vue, '>sinkron</span>'), 'baris bersinkron hidup ditandai "sinkron"');
    $ok(! str_contains($vue, 'sinkron mati'), '  tulisan "sinkron mati" sudah tidak dipakai');
    $ok(str_contains($vue, 'belum disinkronkan'), '  keadaan mati tetap dikatakan, dengan kata "belum disinkronkan"');

    echo "\n{$lolos} lolos, {$gagal} gagal\n";
} catch (\Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n";
    $gagal++;
} finally {
    DB::rollBack();
    echo "(transaksi di-rollback — tidak ada data tersisa)\n";
}
exit($gagal ? 1 : 0);

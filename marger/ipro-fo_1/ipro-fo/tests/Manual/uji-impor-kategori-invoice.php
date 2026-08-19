<?php
// Uji impor Kategori Invoice: contoh format, penerimaan berkas sah, dan penolakan menyeluruh
// saat ada baris yang salah. Dibungkus transaksi lalu di-rollback.

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApCategory;
use App\Http\Controllers\Web\BusinessSupport\Payable\ApCategoryController;

$lolos = 0;
$gagal = 0;
$ok = function (bool $c, string $n) use (&$lolos, &$gagal) {
    $c ? $lolos++ : $gagal++;
    echo ($c ? '  OK    ' : '  GAGAL ').$n."\n";
};

DB::beginTransaction();
try {
    $user = App\Models\User::find(1);
    $ctrl = app(ApCategoryController::class);
    $u    = substr((string) microtime(true), -5);

    $debit  = BsAccount::where('is_postable', true)->where('type', 'expense')->value('code');
    $hutang = BsAccount::where('is_postable', true)->where('type', 'liability')->value('code') ?: $debit;
    $ok((bool) $debit && (bool) $hutang, "akun uji tersedia: debit {$debit}, hutang {$hutang}");

    $unggah = function (string $isi) use ($user, $ctrl) {
        $tmp = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($tmp, $isi);
        $r = Request::create('/x', 'POST', [], [], ['berkas' => new UploadedFile($tmp, 'impor.csv', 'text/csv', null, true)]);
        app()->instance('request', $r);
        $r->setUserResolver(fn () => $user);   // resolver SESUDAH penukaran instance

        return $ctrl->import($r);
    };
    $pesan = function ($resp, string $kunci) {
        $s = $resp->getSession();

        return (string) ($s?->get($kunci) ?? '');
    };

    echo "\n══ Contoh format bisa diunduh ══\n";
    $rt = Request::create('/x', 'GET');
    app()->instance('request', $rt);
    $rt->setUserResolver(fn () => $user);
    ob_start();
    $ctrl->template($rt)->sendContent();
    $csv = ob_get_clean();
    $ok(str_contains($csv, 'kode'), 'berisi baris judul');
    foreach (['keterangan', 'akun_debit', 'akun_hutang', 'aktif'] as $k) {
        $ok(str_contains($csv, $k), '  kolom "'.$k.'" ada');
    }
    $ok(substr($csv, 0, 3) === chr(0xEF).chr(0xBB).chr(0xBF), '  ber-BOM supaya Excel membaca aksen dengan benar');
    $ok(substr_count(trim($csv), "\n") >= 1, '  ada baris contoh, bukan judul saja');

    echo "\n══ Berkas sah: yang belum ada DIBUAT ══\n";
    $sebelum = BsApCategory::count();
    $kodeA = 'UJI-A-'.$u;
    $kodeB = 'UJI-B-'.$u;
    $unggah("kode,keterangan,akun_debit,akun_hutang,aktif\n"
        ."{$kodeA},KATEGORI UJI A,{$debit},{$hutang},1\n"
        ."{$kodeB},KATEGORI UJI B,{$debit},{$hutang},0\n");
    $ok(BsApCategory::count() === $sebelum + 2, 'dua kategori baru tersimpan');
    $a = BsApCategory::where('code', $kodeA)->first();
    $b = BsApCategory::where('code', $kodeB)->first();
    $ok($a && $a->name === 'KATEGORI UJI A', '  keterangannya tersimpan: '.$a?->name);
    $ok($a && $a->debitAccount?->code === $debit, '  akun debit dicocokkan dari KODE, bukan id');
    $ok($a && (bool) $a->is_active === true, '  aktif=1 → Aktif');
    $ok($b && (bool) $b->is_active === false, '  aktif=0 → Nonaktif');

    echo "\n══ Kode yang sama DIPERBARUI, bukan digandakan ══\n";
    $unggah("kode,keterangan,akun_debit,akun_hutang,aktif\n{$kodeA},KATEGORI UJI A DIUBAH,{$debit},{$hutang},1\n");
    $ok(BsApCategory::where('code', $kodeA)->count() === 1, 'tetap satu baris untuk kode itu');
    $ok(BsApCategory::where('code', $kodeA)->value('name') === 'KATEGORI UJI A DIUBAH', '  keterangannya diperbarui');

    echo "\n══ Satu baris salah → TIDAK ADA yang tersimpan ══\n";
    $jml = BsApCategory::count();
    $namaA = BsApCategory::where('code', $kodeA)->value('name');
    $resp = $unggah("kode,keterangan,akun_debit,akun_hutang,aktif\n"
        ."{$kodeA},SEHARUSNYA TIDAK TERSIMPAN,{$debit},{$hutang},1\n"
        ."UJI-C-{$u},KATEGORI UJI C,9999999999,{$hutang},1\n");
    $ok(BsApCategory::count() === $jml, 'jumlah kategori tidak berubah: '.BsApCategory::count());
    $ok(BsApCategory::where('code', $kodeA)->value('name') === $namaA,
        '  baris yang SAH pun tidak ikut tersimpan — seluruh berkas ditolak');
    $ok(! BsApCategory::where('code', 'UJI-C-'.$u)->exists(), '  baris yang salah tidak dibuat');
    $ok(str_contains($pesan($resp, 'error'), 'tidak ada data yang diubah'), '  pesannya menerangkan itu');
    $ok(str_contains($pesan($resp, 'error'), '9999999999'), '  menyebut akun yang tidak ditemukan');

    echo "\n══ Baris kosong & tanpa judul ══\n";
    $jml2 = BsApCategory::count();
    $unggah("UJI-D-{$u},KATEGORI UJI D,{$debit},{$hutang},1\n\n");
    $ok(BsApCategory::count() === $jml2 + 1, 'berkas tanpa baris judul tetap diterima');
    $r2 = $unggah("kode,keterangan,akun_debit,akun_hutang,aktif\n");
    $ok(str_contains($pesan($r2, 'error'), 'Tidak ada baris'), 'berkas berisi judul saja ditolak dengan jelas');

    echo "\n══ Kolom kosong ditolak ══\n";
    $r3 = $unggah("kode,keterangan,akun_debit,akun_hutang,aktif\nUJI-E-{$u},,{$debit},{$hutang},1\n");
    $ok(str_contains($pesan($r3, 'error'), 'keterangan kosong'), 'keterangan kosong ditolak');
    $r4 = $unggah("kode,keterangan,akun_debit,akun_hutang,aktif\n,TANPA KODE,{$debit},{$hutang},1\n");
    $ok(str_contains($pesan($r4, 'error'), 'kode kategori kosong'), 'kode kosong ditolak');

    echo "\n{$lolos} lolos, {$gagal} gagal\n";
} catch (\Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n";
    $gagal++;
} finally {
    DB::rollBack();
    echo "(transaksi di-rollback — tidak ada data tersisa)\n";
}
exit($gagal ? 1 : 0);

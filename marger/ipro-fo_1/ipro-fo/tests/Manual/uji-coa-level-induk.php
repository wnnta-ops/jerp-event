<?php
// Uji Level & Induk pada Tambah/Edit Akun (Chart of Account).
//
// Yang dibuktikan: level dipilih (bukan diterka dari panjang kode), induk wajib dan harus
// TEPAT satu tingkat di atas, kode harus sepanjang levelnya dan melanjutkan kode induk,
// serta parent_id benar-benar tersimpan — sebelum ini selalu NULL untuk akun yang dibuat
// lewat form, sehingga relasi parent() putus.
//
// Dibungkus transaksi lalu di-rollback: tidak meninggalkan satu baris pun di database.

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Domain\BusinessSupport\Models\BsAccount;
use App\Http\Controllers\Web\BusinessSupport\Setup\AccountController;

$lolos = 0;
$gagal = 0;
$ok = function (bool $c, string $n) use (&$lolos, &$gagal) {
    $c ? $lolos++ : $gagal++;
    echo ($c ? '  OK    ' : '  GAGAL ').$n."\n";
};

DB::beginTransaction();
try {
    $user = App\Models\User::find(1);
    $ctrl = app(AccountController::class);

    $kirim = function (array $body, ?int $id = null) use ($user, $ctrl) {
        $r = Request::create('/x', $id ? 'PUT' : 'POST', $body);
        app()->instance('request', $r);
        $r->setUserResolver(fn () => $user);   // resolver SESUDAH penukaran instance

        return $id
            ? $ctrl->update($r, BsAccount::findOrFail($id))
            : $ctrl->store($r);
    };
    $tolak = function (array $body, ?int $id = null) use ($kirim) {
        try {
            $kirim($body, $id);

            return null;
        } catch (ValidationException $e) {
            return $e;
        }
    };

    // Induk uji: satu rantai level 1 → 2 → 3 yang dibuat lewat form itu sendiri.
    $u = substr((string) microtime(true), -4);
    $kode1 = '9';                       // level 1, 1 digit
    $kode2 = '9'.$u[0].$u[1];           // level 2, 3 digit
    $kode3 = $kode2.$u[2].$u[3];        // level 3, 5 digit

    echo "══ Level dipilih, bukan diterka ══\n";
    $kirim(['code' => $kode1, 'name' => 'UJI KATEGORI', 'level' => 1, 'parent_id' => null,
        'type' => 'asset', 'normal_balance' => 'D', 'is_postable' => false, 'is_active' => true]);
    $a1 = BsAccount::where('code', $kode1)->first();
    $ok($a1 !== null && (int) $a1->level === 1, 'akun level 1 tersimpan: '.$a1?->code.' level '.$a1?->level);
    $ok($a1 && $a1->parent_id === null && $a1->parent_code === null, '  level 1 tidak berinduk');

    $kirim(['code' => $kode2, 'name' => 'UJI SUB KATEGORI', 'level' => 2, 'parent_id' => $a1->id,
        'type' => 'asset', 'normal_balance' => 'D', 'is_postable' => false, 'is_active' => true]);
    $a2 = BsAccount::where('code', $kode2)->first();
    $ok($a2 !== null && (int) $a2->level === 2, 'akun level 2 tersimpan: '.$a2?->code);
    $ok($a2 && (int) $a2->parent_id === (int) $a1->id,
        '  parent_id TERISI (dulu selalu NULL): '.var_export($a2?->parent_id, true));
    $ok($a2 && $a2->parent_code === $kode1, '  parent_code ikut terisi: '.var_export($a2?->parent_code, true));
    $ok($a2 && $a2->parent && $a2->parent->id === $a1->id, '  relasi parent() menemukan induknya');

    $kirim(['code' => $kode3, 'name' => 'UJI LEDGER', 'level' => 3, 'parent_id' => $a2->id,
        'type' => 'asset', 'normal_balance' => 'D', 'is_postable' => false, 'is_active' => true]);
    $a3 = BsAccount::where('code', $kode3)->first();
    $ok($a3 !== null && (int) $a3->parent_id === (int) $a2->id, 'akun level 3 bertaut ke level 2');

    echo "\n══ Induk harus TEPAT satu tingkat di atas ══\n";
    $e = $tolak(['code' => '9'.$u[0].$u[1].'11', 'name' => 'Lompat', 'level' => 3, 'parent_id' => $a1->id,
        'type' => 'asset', 'normal_balance' => 'D', 'is_postable' => false, 'is_active' => true]);
    $ok($e !== null && array_key_exists('parent_id', $e->errors()),
        'level 3 berinduk level 1 ditolak: '.($e ? implode(' | ', $e->errors()['parent_id'] ?? []) : 'TIDAK DITOLAK'));

    // Diuji lewat EDIT akun level 1 yang barusan dibuat, bukan dengan menciptakan kode baru:
    // kode satu digit hampir semuanya sudah terpakai di CoA sungguhan, sehingga percobaan
    // membuat akun baru akan tersandung aturan unik lebih dulu dan menguji hal yang salah.
    $e = $tolak(['code' => $kode1, 'name' => 'UJI KATEGORI', 'level' => 1, 'parent_id' => $a2->id,
        'type' => 'asset', 'normal_balance' => 'D', 'is_postable' => false, 'is_active' => true], $a1->id);
    $ok($e !== null && array_key_exists('parent_id', $e->errors()),
        'level 1 yang diberi induk ditolak: '.($e ? implode(' | ', $e->errors()['parent_id'] ?? $e->errors()[array_key_first($e->errors())]) : 'TIDAK DITOLAK'));

    $e = $tolak(['code' => '9'.$u[0].$u[1].'12', 'name' => 'Tanpa induk', 'level' => 3, 'parent_id' => null,
        'type' => 'asset', 'normal_balance' => 'D', 'is_postable' => false, 'is_active' => true]);
    $ok($e !== null && array_key_exists('parent_id', $e->errors()), 'level > 1 tanpa induk ditolak');

    echo "\n══ Kode harus sepanjang levelnya & melanjutkan induk ══\n";
    $e = $tolak(['code' => $kode2.'1', 'name' => 'Kurang digit', 'level' => 3, 'parent_id' => $a2->id,
        'type' => 'asset', 'normal_balance' => 'D', 'is_postable' => false, 'is_active' => true]);
    $ok($e !== null && array_key_exists('code', $e->errors()),
        'level 3 berkode 4 digit ditolak: '.($e ? implode(' | ', $e->errors()['code'] ?? []) : 'TIDAK DITOLAK'));

    $e = $tolak(['code' => '77777', 'name' => 'Beda cabang', 'level' => 3, 'parent_id' => $a2->id,
        'type' => 'asset', 'normal_balance' => 'D', 'is_postable' => false, 'is_active' => true]);
    $ok($e !== null && array_key_exists('code', $e->errors()),
        'kode yang tidak melanjutkan kode induk ditolak: '.($e ? implode(' | ', $e->errors()['code'] ?? []) : 'TIDAK DITOLAK'));

    $e = $tolak(['code' => '9A'.$u[1], 'name' => 'Berhuruf', 'level' => 2, 'parent_id' => $a1->id,
        'type' => 'asset', 'normal_balance' => 'D', 'is_postable' => false, 'is_active' => true]);
    $ok($e !== null && array_key_exists('code', $e->errors()), 'kode berhuruf ditolak');

    echo "\n══ Dulu: kode aneh diam-diam jadi level 6 ══\n";
    $e = $tolak(['code' => '1234', 'name' => 'Empat digit', 'level' => 6, 'parent_id' => null,
        'type' => 'asset', 'normal_balance' => 'D', 'is_postable' => true, 'is_active' => true]);
    $ok($e !== null, 'kode 4 digit tidak lagi lolos sebagai level 6 — sekarang ditolak');
    $ok($e && (array_key_exists('code', $e->errors()) || array_key_exists('parent_id', $e->errors())),
        '  galatnya menunjuk kode/induk, bukan diam: '.implode(', ', array_keys($e->errors())));

    echo "\n══ Level akun yang sudah beranak tidak bisa dipindah ══\n";
    $e = $tolak(['code' => $kode2, 'name' => 'UJI SUB KATEGORI', 'level' => 4, 'parent_id' => $a3->id,
        'type' => 'asset', 'normal_balance' => 'D', 'is_postable' => false, 'is_active' => true], $a2->id);
    $ok($e !== null && array_key_exists('level', $e->errors()),
        'level induk yang punya turunan ditolak: '.($e ? implode(' | ', $e->errors()['level'] ?? []) : 'TIDAK DITOLAK'));

    echo "\n══ Edit biasa tetap jalan ══\n";
    $kirim(['code' => $kode3, 'name' => 'UJI LEDGER DIUBAH', 'level' => 3, 'parent_id' => $a2->id,
        'type' => 'asset', 'normal_balance' => 'D', 'is_postable' => false, 'is_active' => true], $a3->id);
    $a3->refresh();
    $ok($a3->name === 'UJI LEDGER DIUBAH', 'nama tersimpan: '.$a3->name);
    $ok((int) $a3->parent_id === (int) $a2->id, '  tautan induknya tetap utuh');

    echo "\n══ Nama tingkatan ══\n";
    $ok(BsAccount::LEVELS === [1 => 'Kategori', 2 => 'Sub Kategori', 3 => 'Ledger', 4 => 'Detil', 5 => 'Detil Transaksi', 6 => 'Detil Sub Transaksi'],
        'urutan level 1..6 sesuai yang diminta: '.implode(' › ', BsAccount::LEVELS));

    echo "\n{$lolos} lolos, {$gagal} gagal\n";
} catch (\Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n";
    $gagal++;
} finally {
    DB::rollBack();
    echo "(transaksi di-rollback — tidak ada data tersisa)\n";
}
exit($gagal ? 1 : 0);

<?php

/**
 * Posting Invoice dari FO — supplier bawaan & keabsahan suppliernya.
 *
 * LATAR: sejak baris BLT & Komisi punya supplier bawaan (b1f4963), kolom Supplier hampir selalu
 * terisi sejak layar terbuka. Itu memperbesar akibat dua lubang yang sebelumnya nyaris tak
 * terasa:
 *
 *   1. JALUR BACA BERHATI-HATI, JALUR TULIS TIDAK. daftarSupplier() hanya menyajikan supplier
 *      AKTIF, tetapi Rule::exists('bs_suppliers','id') pada fromRefund()/bulkRefund() menembak
 *      tabel mentah — tanpa is_active, tanpa deleted_at. Baris GMC membawa supplier_id sendiri
 *      dari FO; bila supplier itu kemudian dinonaktifkan/dihapus, kolomnya tampak KOSONG di
 *      layar (SearchSelect tidak menemukannya di daftar opsi) sementara id-nya tetap terkirim,
 *      dan invoice hutang terbit atas nama supplier yang sudah dihapus. Baru ketahuan saat
 *      kuitansi hendak dibayar — layar Kuitansi hanya menawarkan supplier aktif, jadi hutangnya
 *      mandek tanpa jalur pembayaran.
 *
 *   2. Baris antrean yang membawa supplier basi diteruskan apa adanya ke layar, sehingga
 *      "kolom kosong" punya DUA arti yang tidak terbedakan: belum diisi, atau terisi diam-diam
 *      oleh supplier yang tidak boleh dipakai.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-posting-fo-supplier-sah.php';"
 */

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Http\Controllers\Web\BusinessSupport\Payable\PostingInvoiceController;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};

$user = User::all()->first(fn ($u) => (bool) $u->canModule('ap')) ?? User::first();
auth()->setUser($user);
$ctl = app(PostingInvoiceController::class);

$akunHutang = BsAccount::where('is_postable', true)->where('type', 'liability')->first(['id']);
$akunLawan  = BsAccount::where('is_postable', true)->where('type', 'expense')->first(['id']);

echo "\n=== PRASYARAT ===\n";
$ok('user ber-modul AP ada', (bool) $user);
$ok('akun hutang & lawan ada', $akunHutang && $akunLawan);
if (! $user || ! $akunHutang || ! $akunLawan) { echo "\nPrasyarat kurang.\n"; return; }

/** Props layar Posting Invoice dari FO. */
$layar = function () use ($ctl, $user): array {
    $r = \Illuminate\Http\Request::create('/business-support/ap/posting', 'GET');
    $r->setUserResolver(fn () => $user);
    $r->headers->set('X-Inertia', 'true');
    $r->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());

    return json_decode((string) $ctl->index($r)->toResponse($r)->getContent(), true)['props'] ?? [];
};

/** Kirim satu baris ke fromRefund(); pulangkan galat validasinya (kosong = lolos). */
$posting = function (int $queueId, int $supplierId) use ($ctl, $user): array {
    $r = \Illuminate\Http\Request::create('/uji/posting', 'POST', ['supplier_id' => $supplierId]);
    $r->setUserResolver(fn () => $user);
    $r->setLaravelSession(app('session.store'));
    app('session.store')->flush();
    try {
        $ctl->fromRefund($r, app(\App\Domain\BusinessSupport\Services\JournalService::class), $queueId);

        return ['validasi' => [], 'flash' => app('session.store')->get('error')];
    } catch (\Illuminate\Validation\ValidationException $e) {
        return ['validasi' => $e->errors(), 'flash' => null];
    }
};

DB::beginTransaction();
try {

$sup = BsSupplier::create(['code' => 'ZZ.UJI1', 'name' => 'UJI SUPPLIER SAH', 'is_active' => true]);
$antreanId = DB::table('bs_ap_refund_queue')->insertGetId([
    'tenant_id' => 1, 'kind' => 'gmc', 'refund_no' => 'ZZ-UJI-GMC-1',
    'customer_name' => 'UJI CUSTOMER', 'supplier_id' => $sup->id,
    'amount' => 1_000_000, 'payable_account_id' => $akunHutang->id, 'offset_account_id' => $akunLawan->id,
    'tgl_refund' => now()->toDateString(), 'keterangan' => 'uji supplier sah', 'status' => 'pending',
    'created_at' => now(), 'updated_at' => now(),
]);

echo "\n=== 1. SUPPLIER AKTIF: JALUR NORMAL ===\n";
$baris = collect($layar()['refunds'] ?? [])->firstWhere('id', $antreanId);
$ok('barisnya tampil di layar', (bool) $baris);
$ok('supplier bawaannya diteruskan', (int) ($baris['supplier_id'] ?? 0) === (int) $sup->id, json_encode($baris['supplier_id'] ?? null));
$ok('tidak ditandai basi', ($baris['supplier_basi'] ?? null) === false);
$ok('suppliernya ada di daftar dropdown',
    collect($layar()['suppliers'] ?? [])->contains(fn ($s) => (int) $s['id'] === (int) $sup->id));

echo "\n=== 2. SUPPLIER DINONAKTIFKAN ===\n";
$sup->update(['is_active' => false]);
$props = $layar();
$baris = collect($props['refunds'] ?? [])->firstWhere('id', $antreanId);
$ok('supplier basi TIDAK lagi diteruskan sebagai pilihan',
    ($baris['supplier_id'] ?? null) === null, json_encode($baris['supplier_id'] ?? null));
$ok('barisnya DITANDAI basi', ($baris['supplier_basi'] ?? null) === true);
$ok('suppliernya hilang dari dropdown',
    ! collect($props['suppliers'] ?? [])->contains(fn ($s) => (int) $s['id'] === (int) $sup->id));

// Inilah lubangnya: id yang tak terlihat itu dulu tetap diterima server.
$h = $posting($antreanId, (int) $sup->id);
$ok('posting ke supplier NONAKTIF ditolak validasi', isset($h['validasi']['supplier_id']),
    json_encode($h));
$ok('pesannya menjelaskan sebabnya',
    str_contains(implode(' ', $h['validasi']['supplier_id'] ?? []), 'tidak aktif'),
    json_encode($h['validasi']['supplier_id'] ?? []));
$ok('antreannya tetap pending (tidak terposting)',
    DB::table('bs_ap_refund_queue')->where('id', $antreanId)->value('status') === 'pending');

echo "\n=== 3. SUPPLIER DIHAPUS (SOFT DELETE) ===\n";
$sup->update(['is_active' => true]);
$sup->delete();
$ok('deleted_at terisi', BsSupplier::withTrashed()->whereKey($sup->id)->value('deleted_at') !== null);
$h2 = $posting($antreanId, (int) $sup->id);
$ok('posting ke supplier TERHAPUS ditolak validasi', isset($h2['validasi']['supplier_id']), json_encode($h2));
$ok('antreannya tetap pending', DB::table('bs_ap_refund_queue')->where('id', $antreanId)->value('status') === 'pending');

echo "\n=== 4. SUPPLIER LAIN YANG SAH TETAP DITERIMA ===\n";
$sup->restore();
$supLain = BsSupplier::create(['code' => 'ZZ.UJI2', 'name' => 'UJI SUPPLIER LAIN', 'is_active' => true]);
$h3 = $posting($antreanId, (int) $supLain->id);
$ok('supplier aktif lolos validasi', ! isset($h3['validasi']['supplier_id']), json_encode($h3['validasi']));
$ok('antreannya benar-benar terposting',
    DB::table('bs_ap_refund_queue')->where('id', $antreanId)->value('status') === 'posted',
    (string) DB::table('bs_ap_refund_queue')->where('id', $antreanId)->value('status'));

echo "\n=== 5. BAWAAN PER SUMBER MASIH BERJALAN ===\n";
$blt = DB::table('bs_ap_refund_queue')->insertGetId([
    'tenant_id' => 1, 'kind' => 'blt', 'refund_no' => 'ZZ-UJI-BLT-1',
    'customer_name' => 'UJI BLT', 'supplier_id' => null,
    'amount' => 500_000, 'payable_account_id' => $akunHutang->id, 'offset_account_id' => $akunLawan->id,
    'tgl_refund' => now()->toDateString(), 'keterangan' => 'uji blt', 'status' => 'pending',
    'created_at' => now(), 'updated_at' => now(),
]);
$kom = DB::table('bs_ap_refund_queue')->insertGetId([
    'tenant_id' => 1, 'kind' => 'komisi', 'refund_no' => 'ZZ-UJI-KOM-1',
    'customer_name' => 'UJI KOMISI', 'supplier_id' => null,
    'amount' => 700_000, 'payable_account_id' => $akunHutang->id, 'offset_account_id' => $akunLawan->id,
    'tgl_refund' => now()->toDateString(), 'keterangan' => 'uji komisi', 'status' => 'pending',
    'created_at' => now(), 'updated_at' => now(),
]);
$props5 = $layar();
$bBlt = collect($props5['refunds'] ?? [])->firstWhere('id', $blt);
$bKom = collect($props5['refunds'] ?? [])->firstWhere('id', $kom);
$idBlt = (int) BsSupplier::where('code', 'SP.00222')->value('id');
$idKom = (int) BsSupplier::where('code', 'SP.00223')->value('id');
$ok('SP.00222 & SP.00223 ada di master', $idBlt > 0 && $idKom > 0, "blt=$idBlt kom=$idKom");
$ok('baris BLT berbawaan SP.00222', (int) ($bBlt['supplier_id'] ?? 0) === $idBlt, json_encode($bBlt['supplier_id'] ?? null));
$ok('baris Komisi berbawaan SP.00223', (int) ($bKom['supplier_id'] ?? 0) === $idKom, json_encode($bKom['supplier_id'] ?? null));
$ok('keduanya tidak ditandai basi',
    ($bBlt['supplier_basi'] ?? null) === false && ($bKom['supplier_basi'] ?? null) === false);

echo "\n=== 6. BAWAAN IKUT DISARING KEAKTIFANNYA ===\n";
// supplierBawaanPerSumber() memang sudah menyaring is_active; dikunci di sini supaya tidak
// diam-diam longgar seperti jalur antrean tadi.
$supBlt = BsSupplier::where('code', 'SP.00222')->first();
$supBlt->update(['is_active' => false]);
$bBlt2 = collect($layar()['refunds'] ?? [])->firstWhere('id', $blt);
$ok('bawaan BLT tidak dipakai saat suppliernya nonaktif',
    ($bBlt2['supplier_id'] ?? null) === null, json_encode($bBlt2['supplier_id'] ?? null));
$supBlt->update(['is_active' => true]);

echo "\n=== 7. SUMBER LAYAR (Vue) ===\n";
$vue = file_get_contents(resource_path('js/Pages/BusinessSupport/Payable/Posting.vue')) ?: '';
$ok('watcher prefill tidak menimpa pilihan yang sudah ada',
    str_contains($vue, '! supplierSel.value[it.id]'));
$ok('pilihan baris yang hilang dibersihkan', str_contains($vue, 'delete supplierSel.value['));
/* Diperiksa pada TAG SearchSelect-nya, bukan di sembarang tempat dalam berkas: komentar di
   atasnya menyebut ":clearable=\"false\"" secara harfiah, sehingga pencarian se-berkas akan
   tetap lulus meski propnya dicopot dari tagnya. Uji mutasi menemukan celah ini. */
$tagSupplier = '';
if (preg_match('/<SearchSelect v-model="supplierSel\[it\.id\]"[\s\S]*?\/>/', $vue, $m)) {
    $tagSupplier = $m[0];
}
$ok('tag SearchSelect supplier ditemukan', $tagSupplier !== '');
$ok('dropdown supplier benar-benar tanpa tombol hapus',
    str_contains($tagSupplier, ':clearable="false"'), $tagSupplier);
$ok('penanda supplier basi digambar', str_contains($vue, 'it.supplier_basi'));
$ok('konfirmasi posting menyebut nama suppliernya',
    str_contains($vue, 'untuk ${namaSupplier(Number(supplier_id))}'));

echo "\n=== 8. VALIDASI DIPAKAI DI KEDUA JALUR TULIS ===\n";
$src = file_get_contents(app_path('Http/Controllers/Web/BusinessSupport/Payable/PostingInvoiceController.php')) ?: '';
$ok('aturan supplierTerpakai() dipakai 2x (satuan + gabungan)',
    substr_count($src, 'self::supplierTerpakai()') === 2, (string) substr_count($src, 'self::supplierTerpakai()'));
$ok('tidak ada lagi Rule::exists bs_suppliers yang telanjang',
    ! preg_match("/Rule::exists\('bs_suppliers', 'id'\)\]/", $src));
$ok('aturannya menyaring is_active DAN deleted_at',
    str_contains($src, "->where('is_active', 1)->whereNull('deleted_at')"));

} finally {
    DB::rollBack();
}

echo "\n=== HASIL ===\n";
echo "  lulus: $lulus · gagal: $gagal\n";
echo $gagal === 0 ? "  SEMUA LULUS\n" : "  ADA YANG GAGAL\n";

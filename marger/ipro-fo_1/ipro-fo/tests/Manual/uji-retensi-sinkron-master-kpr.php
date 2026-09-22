<?php

/**
 * "Sinkron dengan Master Data KPR" pada Kategori Retensi, dan penggabungan kolomnya.
 *
 * Permintaannya dua hal yang saling mengunci: TANAH MATANG 50%/20%/30% digabung jadi satu
 * kolom (begitu pula TOPPING OFF 20%/40%), dan kategori tidak bisa disimpan sebelum
 * disinkronkan. Yang dijaga di sini, berurut dari yang paling mahal bila salah:
 *
 *  1. UANG TIDAK BOLEH HILANG SAAT DIGABUNG. Nilai sel disimpan berkunci
 *     retention_category_id MENTAH. Kolom gabungan yang hanya membaca satu id wakil akan
 *     meninggalkan pencairan milik anggota lain pada kunci yang tak pernah dibaca siapa pun —
 *     nominalnya lenyap dari rekap tanpa satu pun galat, dan cacat seperti itu pernah terjadi
 *     di layar ini sebelumnya (lihat komentar KAT_TANPA_KATEGORI).
 *  2. JUDUL DAN NILAI HARUS SEJALAN. Keduanya dirakit sebagai dua array terpisah dan
 *     dipasangkan layar menurut INDEX; satu sisi yang berubah sendiri menggeser seluruh angka
 *     ke kolom tetangga, juga tanpa galat.
 *  3. KATEGORI YANG BELUM SINKRON TIDAK DIBUANG — ia berdiri sebagai kolomnya sendiri.
 *     Dibuang, pencairannya hilang dari layar sementara masternya masih ada.
 *  4. TIDAK BISA DISIMPAN SEBELUM SINKRON, untuk tambah MAUPUN sunting.
 *  5. KATEGORI LAMA TETAP BISA DISUNTING. Validasi wajib tanpa backfill akan mengunci seluruh
 *     baris yang sudah ada: membuka Edit lalu Simpan ditolak walau tak ada yang disentuh.
 *  6. code/name TIDAK ikut diubah saat menggabungkan — dua tempat lain menentukan BENTUK
 *     JURNAL dan auto-isi SSP/BPHTB dari teks itu.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-retensi-sinkron-master-kpr.php';"
 */

use App\Domain\BankLoan\Models\RetentionCategory;
use App\Http\Controllers\Web\BankLoan\MasterDataKprController;
use App\Http\Controllers\Web\BankLoan\Setup\RetentionCategoryController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};

$user = User::all()->first(fn ($u) => $u->can('bankloan.setup.manage')) ?? User::first();
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
auth()->setUser($user);
app()->instance('tenant.id', (int) $user->tenant_id);
$TENANT = (int) $user->tenant_id;

if (! Schema::hasColumn('retention_categories', 'kpr_sync')) {
    echo "Kolom retention_categories.kpr_sync belum ada — jalankan migrasi 2026_09_22_160000 dulu.\n";
    return;
}

$setup = new RetentionCategoryController;
$req = function (array $data) use ($user) {
    $r = Request::create('/uji', 'POST', $data);
    $r->setUserResolver(fn () => $user);

    return $r;
};
/** Pesan galat validasi untuk satu kolom, atau '' bila lolos. */
$galat = function (callable $fn, string $kolom): string {
    try { $fn(); return ''; }
    catch (ValidationException $e) { return implode(' ', $e->errors()[$kolom] ?? ['(kolom lain)']); }
};
/** Kolom & baris layar Master Data KPR lewat refleksi (keduanya private). */
$kolomLayar = function () {
    $c = app()->make(MasterDataKprController::class);
    $m = new ReflectionMethod(MasterDataKprController::class, 'retensiKolom');
    $m->setAccessible(true);

    return $m->invoke($c);
};
$judulLayar = function () {
    $c = app()->make(MasterDataKprController::class);
    $m = new ReflectionMethod(MasterDataKprController::class, 'retensiHeaders');
    $m->setAccessible(true);

    return $m->invoke($c);
};

DB::beginTransaction();

try {
    $uniq = strtoupper(substr(uniqid(), -5));

    echo "\n=== 1. Tidak bisa disimpan sebelum disinkronkan ===\n";

    $dasar = ['code' => 'UJI-'.$uniq, 'name' => 'Kategori Uji '.$uniq, 'percentage' => 30, 'is_active' => 1];
    $pesan = $galat(fn () => $setup->store($req($dasar)), 'kpr_sync');
    $ok('tambah tanpa sinkron ditolak', $pesan !== '', 'tersimpan tanpa sinkron');
    // Pesannya dalam bahasa Indonesia & menyebut apa yang kurang — "The kpr sync field is
    // required" tidak memberi tahu operator apa pun tentang Master Data KPR.
    $ok('  pesannya menjelaskan apa yang kurang',
        str_contains(strtolower($pesan), 'sinkron') && str_contains(strtolower($pesan), 'master data kpr'), $pesan);

    $pesan = $galat(fn () => $setup->store($req($dasar + ['kpr_sync' => 'entah_apa'])), 'kpr_sync');
    $ok('pilihan di luar daftar ditolak', $pesan !== '');

    $setup->store($req($dasar + ['kpr_sync' => 'tanah_matang']));
    $baru = RetentionCategory::where('code', 'UJI-'.$uniq)->first();
    $ok('dengan sinkron: tersimpan', $baru !== null);
    // Lupa menambahkan kolomnya ke $fillable = validasi lulus, notifikasi "tersimpan" muncul,
    // kolomnya NULL, dan barisnya langsung tidak bisa disunting lagi. Gagal tanpa satu pun pesan.
    $ok('  nilainya benar-benar tersimpan (ada di $fillable)', ($baru->kpr_sync ?? null) === 'tanah_matang',
        json_encode($baru->kpr_sync ?? null));

    echo "\n=== 2. Aturan yang sama berlaku saat menyunting ===\n";

    $pesan = $galat(fn () => $setup->update($req($dasar), $baru), 'kpr_sync');
    $ok('sunting tanpa sinkron ditolak', $pesan !== '');
    /* BACKFILL MIGRASI — diperiksa lebih dulu, dan TIDAK bersyarat.
       Tanpa backfill, validasi "wajib sinkron" mengunci seluruh kategori yang sudah ada:
       membuka Edit lalu Simpan ditolak walau operator tidak menyentuh apa pun. Ditulis sebagai
       `if ($lama)` saja, asersinya akan diam-diam HILANG justru pada keadaan yang hendak
       dijaga — sebab tanpa backfill tidak ada satu pun kategori ber-kpr_sync untuk dipungut. */
    $peta = ['TANAH MATANG' => 'tanah_matang', 'TOPPING OFF' => 'topping_off', 'PONDASI' => 'pondasi',
        'DAJAM' => 'dajam', 'BAST' => 'bast_ajb', 'AJB' => 'bast_ajb'];
    $belum = [];
    foreach (RetentionCategory::where('code', 'not like', 'UJI-%')->get(['id', 'code', 'name', 'kpr_sync']) as $c) {
        $teks = strtoupper(trim((string) ($c->code ?: $c->name)));
        foreach ($peta as $kata => $grup) {
            if (str_contains($teks, $kata)) {
                if ($c->kpr_sync !== $grup) { $belum[] = $c->code.' → '.json_encode($c->kpr_sync).' (harusnya '.$grup.')'; }
                break;
            }
        }
    }
    $ok('backfill migrasi memetakan seluruh kategori yang namanya dikenali', $belum === [], implode('; ', $belum));

    $lama = RetentionCategory::where('code', 'not like', 'UJI-%')->whereNotNull('kpr_sync')->first();
    $ok('  sehingga ada kategori lama yang siap disunting', $lama !== null,
        'tak satu pun kategori lama tersinkron — seluruhnya terkunci');
    if ($lama) {
        $pesanLama = $galat(fn () => $setup->update($req([
            'code' => $lama->code, 'name' => $lama->name, 'percentage' => (float) $lama->percentage,
            'is_active' => 1, 'kpr_sync' => $lama->kpr_sync,
        ]), $lama), 'kpr_sync');
        $ok('kategori lama tetap bisa disunting (backfill migrasi bekerja)', $pesanLama === '', $pesanLama);
    }

    echo "\n=== 3. Kategori bersinkron sama digabung jadi SATU kolom ===\n";

    // Dua kategori tambahan pada grup yang sama — inilah TANAH MATANG 50%/20%/30% versi uji.
    $setup->store($req(['code' => 'UJI-'.$uniq.'-B', 'name' => 'Uji B', 'percentage' => 20, 'is_active' => 1, 'kpr_sync' => 'tanah_matang']));
    $setup->store($req(['code' => 'UJI-'.$uniq.'-C', 'name' => 'Uji C', 'percentage' => 30, 'is_active' => 1, 'kpr_sync' => 'topping_off']));
    $b = RetentionCategory::where('code', 'UJI-'.$uniq.'-B')->first();

    $kolom = $kolomLayar();
    $tanah = collect($kolom)->firstWhere('key', 'tanah_matang');
    $ok('ketiga kategori tanah matang berdiri sebagai SATU kolom', $tanah !== null);
    $ok('  kolomnya memuat SELURUH id anggotanya',
        in_array($baru->id, $tanah['ids'] ?? [], true) && in_array($b->id, $tanah['ids'] ?? [], true),
        json_encode($tanah['ids'] ?? []));
    $ok('  judulnya nama grup, bukan kode salah satu anggotanya',
        ($tanah['label'] ?? '') === RetentionCategory::KPR_SYNC['tanah_matang'], (string) ($tanah['label'] ?? '-'));

    /* Diperiksa lagi pada grup yang label grupnya BERBEDA dari kode anggotanya. Untuk
       tanah_matang keduanya kebetulan sama-sama "TANAH MATANG", sehingga penjaga di atas
       tetap hijau walau judulnya sebenarnya diambil dari kode kategori. bast_ajb membedakan
       keduanya: kodenya "BAST + AJB", nama grupnya "BAST & AJB". */
    $bast = collect($kolom)->firstWhere('key', 'bast_ajb');
    if ($bast) {
        $kodeAnggota = RetentionCategory::whereIn('id', $bast['ids'])->pluck('code')->all();
        $ok('  dibuktikan lagi pada grup yang namanya beda dari kode anggotanya',
            ($bast['label'] ?? '') === RetentionCategory::KPR_SYNC['bast_ajb']
            && ! in_array($bast['label'], $kodeAnggota, true),
            json_encode([$bast['label'] ?? null, $kodeAnggota]));
    }
    // code/name kategori TIDAK boleh ikut diubah: dua tempat lain menentukan bentuk jurnal &
    // auto-isi SSP/BPHTB dari teks itu.
    $ok('  kode kategorinya sendiri tidak ikut diubah',
        RetentionCategory::find($baru->id)->code === 'UJI-'.$uniq);

    $judul = $judulLayar();
    $ok('judul & jumlah kolom sejalan dengan daftar kolomnya',
        count($judul) === count($kolom) + 1 && array_slice($judul, 0, -1) === array_column($kolom, 'label'),
        json_encode($judul));
    $ok('  hanya muncul sekali di daftar judul',
        count(array_keys($judul, RetentionCategory::KPR_SYNC['tanah_matang'], true)) === 1, json_encode($judul));

    echo "\n=== 4. Nilai sel DIJUMLAH antar anggota, tanggal ambil yang terbaru ===\n";

    // Inilah penjaga "uang tidak hilang": nilai disimpan berkunci id MENTAH tiap anggota.
    $sel = new ReflectionMethod(MasterDataKprController::class, 'selRetensiGrup');
    $sel->setAccessible(true);
    $ctl = app()->make(MasterDataKprController::class);
    $isi = [
        $baru->id => ['t' => '2026-03-01', 'n' => 100000000.0],
        $b->id    => ['t' => '2026-05-20', 'n' => 50000000.0],
    ];
    $hasil = $sel->invoke($ctl, $isi, $tanah['ids']);
    $ok('nominalnya dijumlah, bukan diambil salah satu', ($hasil['n'] ?? 0) === 150000000.0, json_encode($hasil));
    $ok('  tanggalnya yang TERBARU', ($hasil['t'] ?? null) === '2026-05-20', json_encode($hasil));
    // null, bukan nol: layar membedakan "belum ada pencairan" (—) dari "dicairkan Rp 0".
    $ok('tanpa pencairan sama sekali → null (sel "—"), bukan nol',
        $sel->invoke($ctl, [], $tanah['ids']) === null);
    $ok('  anggota yang belum punya nilai tidak menggagalkan penjumlahan',
        ($sel->invoke($ctl, [$b->id => ['t' => null, 'n' => 7.0]], $tanah['ids'])['n'] ?? 0) === 7.0);

    echo "\n=== 5. Kategori yang belum sinkron tetap punya kolomnya sendiri ===\n";

    // Dibuang, pencairannya hilang dari layar sementara masternya masih ada — persis cacat
    // yang dulu diperbaiki pada kolom ini.
    DB::table('retention_categories')->where('id', $b->id)->update(['kpr_sync' => null]);
    $kolom2 = $kolomLayar();
    $ok('ia tidak hilang dari daftar kolom',
        collect($kolom2)->contains(fn ($k) => in_array($b->id, $k['ids'], true)), json_encode(array_column($kolom2, 'label')));
    $ok('  dan tidak ikut menyatu dengan grup mana pun',
        count(collect($kolom2)->firstWhere('key', 'tanah_matang')['ids'] ?? []) < count($tanah['ids']));

    echo "\n==================================================\n";
    echo "  LULUS $lulus · GAGAL $gagal\n";
    if ($gagal === 0) { echo "  SEMUA UJI LULUS\n"; }
    echo "==================================================\n";
} finally {
    DB::rollBack();
    echo "(data uji dibatalkan)\n";
}

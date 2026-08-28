<?php

declare(strict_types=1);

/**
 * Uji: Numerator Back Office (Settings → Numerator Back Office).
 *
 * Jalankan:  docker exec ipro-fo-app php artisan tinker /var/www/html/tests/Manual/uji-numerator-back-office.php
 *
 * Pemeriksaan yang PALING menentukan ada di bagian 2: membuktikan master benar-benar DIPAKAI.
 * Penyambungan ini sempat tampak beres padahal `scope` dikirim sebagai isi context alih-alih
 * argumen ketiga — akibatnya tidak satu pun baris ditemukan, setiap penerbitan diam-diam
 * jatuh ke cara lama, dan layar Settings tidak berpengaruh apa pun. Nomornya tetap benar,
 * jadi tidak ada yang terlihat salah. Yang bisa menangkapnya hanya pemeriksaan yang MENGUBAH
 * format lalu menuntut keluarannya ikut berubah.
 *
 * Semua yang menulis dibungkus transaksi lalu di-rollback.
 */

use App\Domain\BusinessSupport\Services\BoNumberService;
use App\Domain\BusinessSupport\Support\BoDocumentNumbers;
use App\Domain\Foundation\Numerator\Models\Numerator;
use App\Domain\Foundation\Numerator\Services\NumeratorService;
use App\Http\Controllers\Web\Settings\BoNumeratorController;
use App\Models\User;
use Illuminate\Support\Carbon;
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

$bo = app(BoNumberService::class);
$baris = fn (string $code) => Numerator::withoutGlobalScopes()
    ->where('scope', BoDocumentNumbers::SCOPE)->where('code', $code)->first();

echo "\n=== 1. Master terpasang & terpisah dari Front Office ===\n";

$katalog = BoDocumentNumbers::all();
$cek('katalog memuat 35 dokumen', count($katalog) === 35, (string) count($katalog));

$jmlBo = Numerator::withoutGlobalScopes()->where('scope', 'bo')->count();
$jmlFo = Numerator::withoutGlobalScopes()->where('scope', 'fo')->count();
$cek('35 baris Back Office tertanam', $jmlBo === 35, (string) $jmlBo);
// Dokumen Front Office tidak boleh ikut terbawa.
$cek('17 baris Front Office tetap utuh', $jmlFo === 17, (string) $jmlFo);

$kurang = array_values(array_diff(BoDocumentNumbers::codes(),
    Numerator::withoutGlobalScopes()->where('scope', 'bo')->pluck('code')->all()));
$cek('tidak ada kode katalog yang belum punya baris', $kurang === [], implode(',', $kurang));

// Setiap format WAJIB memuat {SEQ}; tanpa itu nomornya sama terus dan dokumen kedua ditolak
// indeks unik.
$tanpaSeq = Numerator::withoutGlobalScopes()->where('scope', 'bo')->get()
    ->reject(fn ($n) => str_contains((string) $n->format, '{SEQ}'))->pluck('code')->all();
$cek('semua format memuat {SEQ}', $tanpaSeq === [], implode(',', $tanpaSeq));

echo "\n=== 2. Master BENAR-BENAR dipakai, bukan sekadar tersimpan ===\n";

DB::beginTransaction();
try {
    $n = $baris('JM');
    $cek('baris JM ada', $n !== null);

    $seqAwal = (int) $n->current_seq;
    $nomor = $bo->next('JM', Carbon::parse('2026-08-15'));

    // Bentuknya mengikuti format master.
    $cek('nomor mengikuti format master', $nomor === sprintf('JM/2608/%04d', $seqAwal + 1), $nomor);
    // Dan penghitungnya benar-benar naik — kalau jatuh ke cara lama, ini tetap di angka awal.
    $cek('current_seq ikut naik', (int) $baris('JM')->current_seq === $seqAwal + 1,
        $seqAwal.' → '.$baris('JM')->current_seq);

    // Bukti paling keras: UBAH formatnya, keluarannya HARUS ikut berubah.
    $n->update(['format' => 'UJI-{PREFIX}/{YYYY}/{MM_ROMAN}/{SEQ}']);
    $nomor2 = $bo->next('JM', Carbon::parse('2026-08-15'));
    $cek('mengubah format mengubah nomornya',
        $nomor2 === sprintf('UJI-JM/2026/VIII/%04d', $seqAwal + 2), $nomor2);

    echo "\n=== 2b. Penerbit dokumennya benar-benar tersambung ===\n";

    // Menguji BoNumberService saja tidak cukup: yang harus terbukti adalah ke-20 berkas
    // penerbit dokumen memang memanggilnya. Dipanggil lewat refleksi karena methodnya privat
    // — memanggil alur simpannya akan menulis dokumen sungguhan.
    $lewatRefleksi = function (object $svc, string $method, array $arg) {
        $m = new ReflectionMethod($svc, $method);
        $m->setAccessible(true);

        return $m->invokeArgs($svc, $arg);
    };

    $penerbit = [
        ['JAP', app(App\Domain\BusinessSupport\Services\JournalService::class), 'nextNumber', ['ap', Carbon::parse('2026-08-15')]],
        ['SPK', app(App\Domain\BusinessSupport\Services\ContractorService::class), 'nextNumber', ['2026-08-15']],
        ['BON', app(App\Domain\BusinessSupport\Services\CashAdvanceService::class), 'nextNumber', ['2026-08-15']],
    ];

    foreach ($penerbit as [$code, $svc, $method, $arg]) {
        $sebelum = (int) $baris($code)->current_seq;
        try {
            $nomor = $lewatRefleksi($svc, $method, $arg);
            $sesudah = (int) $baris($code)->current_seq;
            // Kalau penerbitnya masih memakai cara lama, current_seq TIDAK akan bergerak.
            $cek("{$code}: penerbitnya menaikkan penghitung master", $sesudah === $sebelum + 1,
                $sebelum.' → '.$sesudah.' (nomor: '.(is_string($nomor) ? $nomor : json_encode($nomor)).')');
        } catch (\Throwable $e) {
            $cek("{$code}: penerbitnya bisa dipanggil", false, $e->getMessage());
        }
    }

    echo "\n=== 3. Segmen tanggal dari TANGGAL DOKUMEN, bukan hari ini ===\n";

    $n->update(['format' => '{PREFIX}/{YY}{MM}/{SEQ}']);
    // Dokumen bertanggal mundur harus tetap memakai bulannya sendiri.
    $mundur = $bo->next('JM', Carbon::parse('2026-03-09'));
    $cek('dokumen Maret memakai segmen 2603', str_contains($mundur, '/2603/'), $mundur);
    $cek('bukan bulan berjalan', ! str_contains($mundur, '/'.now()->format('ym').'/') || now()->format('ym') === '2603', $mundur);

    echo "\n=== 4. Token khusus terkirim ===\n";

    $inv = $bo->next('AP.INV', Carbon::parse('2026-08-15'), ['KATEGORI' => 'BIAYA-TELP-PULSA']);
    $cek('kategori masuk ke nomor Invoice Hutang',
        str_contains($inv, '/BIAYA-TELP-PULSA/'), $inv);
    $cek('bulan Romawi dipakai', str_contains($inv, '/VIII/'), $inv);

    $bc = $bo->next('BC', Carbon::parse('2026-08-15'), ['PROJ' => 'JRK3']);
    $cek('kode proyek masuk ke nomor Budget', str_contains($bc, '/JRK3/'), $bc);

    echo "\n=== 5. Nomor MELANJUTKAN dokumen yang sudah ada ===\n";

    // Kalau penghitungnya mulai dari nol, nomor berikutnya mengulang nomor yang sudah terbit —
    // dan karena kolomnya unik, dokumen sah berikutnya akan DITOLAK database.
    foreach (['JAP' => 'bs_journals', 'JKB' => 'bs_journals', 'SPK' => 'bs_work_orders'] as $code => $tabel) {
        $d = $katalog[$code];
        $tertinggi = BoNumberService::urutTertinggi($code, $d['tabel'], $d['kolom'], $d['prefix']);
        $cek("{$code}: penghitungnya tidak di bawah nomor tertinggi yang sudah terbit ({$tertinggi})",
            (int) $baris($code)->current_seq >= $tertinggi,
            $baris($code)->current_seq.' vs '.$tertinggi);
    }

    echo "\n=== 6. Jalan mundur saat barisnya tidak ada ===\n";

    // Dokumen tidak boleh gagal terbit hanya karena baris pengaturannya hilang.
    $hasil = $bo->next('KODE-TIDAK-ADA', Carbon::parse('2026-08-15'), [], fn () => 'CARA-LAMA/0001');
    $cek('jatuh ke cara lama, bukan melempar', $hasil === 'CARA-LAMA/0001', $hasil);

    echo "\n=== 7. SIP — bentuk dari master, urutan dari penghitungnya sendiri ===\n";

    $sip = $bo->render('SIP', Carbon::parse('2026-08-15'), 7, [], fn () => 'LAMA');
    $cek('bentuknya mengikuti format master', $sip === '007/FIN/MITRA-SI/VIII/2026', $sip);
    // render() TIDAK boleh menaikkan penghitung master — urutan SIP bukan miliknya.
    $seqSip = (int) $baris('SIP')->current_seq;
    $bo->render('SIP', Carbon::parse('2026-08-15'), 8);
    $cek('merakit nomor SIP tidak menaikkan penghitung master',
        (int) $baris('SIP')->current_seq === $seqSip, $seqSip.' → '.$baris('SIP')->current_seq);

    echo "\n=== 8. Front Office tidak terganggu ===\n";

    $fo = app(NumeratorService::class);
    $tts = Numerator::withoutGlobalScopes()->where('scope', 'fo')->where('code', 'TTS')->first();
    $cek('baris TTS Front Office masih ada', $tts !== null);
    if ($tts) {
        $seqTts = (int) $tts->current_seq;
        $nomorTts = $fo->next('TTS');   // tanpa scope = 'fo', seperti pemanggil lama
        $cek('numerator FO tetap terbit', $nomorTts !== '', $nomorTts);
        $cek('yang naik baris FO, bukan BO',
            (int) $tts->fresh()->current_seq === $seqTts + 1);
    }
} finally {
    DB::rollBack();
}

echo "\n=== 9. Penjagaan di layar ===\n";

$user = User::query()->get()->first(fn ($u) => $u->can('system.setup.numerator'));
$cek('ada pengguna berwenang', $user !== null);

if ($user) {
    $req = function (array $data = [], string $method = 'POST') use ($user) {
        $r = Illuminate\Http\Request::create('/x', $method, $data);
        $r->setUserResolver(fn () => $user);

        return $r;
    };
    $ctl = app(BoNumeratorController::class);

    DB::beginTransaction();
    try {
        $baik = ['code' => 'UJI.NUM', 'prefix' => 'UJI', 'format' => '{PREFIX}/{YY}/{SEQ}',
            'seq_padding' => 4, 'reset_strategy' => 'yearly', 'current_seq' => 0];

        // Format tanpa {SEQ} akan menghasilkan nomor kembar selamanya.
        $ditolak = false;
        try {
            $ctl->store($req(array_merge($baik, ['format' => '{PREFIX}/{YY}'])));
        } catch (Illuminate\Validation\ValidationException $e) {
            $ditolak = array_key_exists('format', $e->errors());
        }
        $cek('format tanpa {SEQ} ditolak', $ditolak);

        $ctl->store($req($baik));
        $baru = Numerator::withoutGlobalScopes()->where('scope', 'bo')->where('code', 'UJI.NUM')->first();
        $cek('numerator baru tersimpan', $baru !== null);
        $cek('scope-nya bo', $baru?->scope === BoDocumentNumbers::SCOPE, (string) $baru?->scope);

        // Kode kembar tidak boleh lolos: MySQL memperlakukan NULL project_id sebagai nilai
        // berbeda, jadi indeks unik TIDAK mencegahnya.
        $kembar = false;
        try {
            $ctl->store($req($baik));
        } catch (Illuminate\Validation\ValidationException $e) {
            $kembar = array_key_exists('code', $e->errors());
        }
        $cek('kode kembar ditolak', $kembar);

        // Nomor urut tidak boleh diturunkan.
        $baru->update(['current_seq' => 10]);
        $mundur = false;
        try {
            $ctl->update($req(array_merge($baik, ['current_seq' => 3]), 'PUT'), $baru->fresh());
        } catch (Illuminate\Validation\ValidationException $e) {
            $mundur = array_key_exists('current_seq', $e->errors());
        }
        $cek('menurunkan nomor urut ditolak', $mundur);

        // Kode tidak ikut berubah saat menyunting — ia kunci ke dokumennya.
        $ctl->update($req(array_merge($baik, ['code' => 'GANTI', 'prefix' => 'UJI2', 'current_seq' => 10]), 'PUT'), $baru->fresh());
        $cek('kode tetap, hanya isian lain yang berubah',
            $baru->fresh()->code === 'UJI.NUM' && $baru->fresh()->prefix === 'UJI2',
            $baru->fresh()->code.'/'.$baru->fresh()->prefix);

        // Dokumen katalog tidak boleh dihapus — penerbitnya akan jatuh ke format di kode.
        $terlindungi = false;
        try {
            $ctl->destroy($req([], 'DELETE'), $baris('JM'));
        } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $terlindungi = $e->getStatusCode() === 422;
        }
        $cek('numerator bawaan tidak bisa dihapus', $terlindungi);
        $cek('barisnya memang masih ada', $baris('JM') !== null);
    } finally {
        DB::rollBack();
    }
}

echo "\n=== 10. Rollback bersih ===\n";
$cek('numerator uji tidak tertinggal',
    Numerator::withoutGlobalScopes()->where('code', 'UJI.NUM')->count() === 0);
$cek('jumlah barisnya kembali seperti semula',
    Numerator::withoutGlobalScopes()->count() === 52,
    (string) Numerator::withoutGlobalScopes()->count());

echo "\n==================================================\n";
echo "  LULUS {$lulus} · GAGAL {$gagal}\n";
echo "==================================================\n";

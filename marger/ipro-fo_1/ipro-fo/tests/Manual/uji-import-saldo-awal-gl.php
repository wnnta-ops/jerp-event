<?php

/**
 * IMPORT SALDO AWAL (General Ledger) — dari Excel/CSV menjadi satu jurnal saldo awal per tahun.
 *
 * APA YANG PERLU DIJAGA DAN KENAPA.
 *   1. TANGGALNYA 31 Desember tahun sebelumnya, bukan 1 Januari. openingJournalLines() membaca
 *      jurnal bertanggal SEBELUM 1 Januari, jadi jurnal bertanggal 1 Januari tidak akan tampil di
 *      halaman tahun yang baru saja diimpor — dan kolom Saldo Awal buku besar tahun itu tetap nol.
 *   2. STATUSNYA 'posted'. openingJournalLines() hanya menghitung jurnal 'posted', sedangkan
 *      JournalService::post secara bawaan membuat 'final'; tanpa penegasan itu saldo yang diimpor
 *      tidak muncul di mana pun dan fiturnya tampak tidak bekerja.
 *   3. SATU TAHUN SEKALI. Mengimpor dua kali melipatgandakan seluruh saldo pembuka, dan jumlahnya
 *      tetap balance — tidak ada satu galat pun yang memberi tahu.
 *   4. TIDAK BALANCE → BATAL SELURUHNYA. Berbeda dari import lain yang melewati baris jelek, di
 *      sini melewati satu baris berarti jurnalnya timpang, dan neraca tahun itu salah sejak hari
 *      pertama. Karena itu satu baris bermasalah membatalkan semuanya.
 *   5. .xls DITOLAK. Tidak ada satu pun mekanisme di aplikasi ini yang bisa membacanya; menerimanya
 *      hanya menghasilkan galat yang membingungkan atas berkas yang isinya sebenarnya benar.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-import-saldo-awal-gl.php';"
 */

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Http\Controllers\Web\BusinessSupport\Gl\PeriodClosingController;
use App\Models\User;
use App\Support\Xlsx\SimpleXlsx;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};
$rp = fn ($v) => (int) round((float) $v);

$user = User::all()->first(fn ($u) => $u->can('gl.saldo-awal.create')) ?? User::first();
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
if (! $user->can('gl.saldo-awal.create')) { echo "User tidak punya izin gl.saldo-awal.create — uji dihentikan.\n"; return; }
auth()->setUser($user);

$ctrl = app(PeriodClosingController::class);
$svcJurnal = app(\App\Domain\BusinessSupport\Services\JournalService::class);

$aset  = BsAccount::where('is_postable', true)->where('type', 'asset')->orderBy('code')->first();
$modal = BsAccount::where('is_postable', true)->where('type', 'equity')->orderBy('code')->first();
if (! $aset || ! $modal) { echo "Akun aset/ekuitas tidak ada — uji dihentikan.\n"; return; }

$TAHUN = 2039; // masa khayalan, tidak bertabrakan dengan data nyata

/** Kirim berkas ke saldoAwalImport() seperti unggahan sungguhan. */
$impor = function (string $namaBerkas, string $isiBiner, int $year) use ($ctrl, $user, $svcJurnal) {
    /* Flash sesi HARUS dibersihkan dulu. Sesi ini dipakai bersama seluruh pemanggilan di berkas
       uji ini, dan `with('error')` yang lama tetap tersimpan bila pemanggilan berikutnya berhasil
       — pemeriksaan lalu membaca galat milik pemanggilan sebelumnya dan menuduh yang salah. */
    session()->forget(['error', 'success', 'warning']);

    $tmp = tempnam(sys_get_temp_dir(), 'sa').'-'.$namaBerkas;
    file_put_contents($tmp, $isiBiner);
    $r = Request::create('/x', 'POST', ['year' => $year], [], ['file' => new UploadedFile($tmp, $namaBerkas, null, null, true)]);
    app()->instance('request', $r);
    $r->setUserResolver(fn () => $user);

    return $ctrl->saldoAwalImport($r, $svcJurnal);
};
/** Ambil pesan flash dari RedirectResponse. */
$pesan = function ($resp, string $kunci): string {
    $bag = $resp->getSession()?->get($kunci);
    if ($bag === null) {
        return '';   // tidak ada pesan — BUKAN string "null"
    }

    return is_string($bag) ? $bag : (string) json_encode($bag);
};
$xlsx = fn (array $rows) => SimpleXlsx::toString($rows);

DB::beginTransaction();

try {
    $svcJurnal->ensurePeriod($TAHUN - 1, 12);

    /* Buku besar ini sudah berisi data lain, jadi yang bisa ditegaskan adalah SELISIHNYA — angka
       mutlak akan berubah setiap kali data uji lain ikut tercatat. */
    $reqAwal = Request::create('/x', 'GET', ['year' => $TAHUN], [], [], ['HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $reqAwal->setUserResolver(fn () => $user);
    app()->instance('request', $reqAwal);
    $debitSebelum = (float) $ctrl->saldoAwalJurnal($reqAwal)->toResponse($reqAwal)->getData(true)['props']['totals']['debit'];

    echo "\n=== 1. IMPORT .xlsx YANG SEIMBANG ===\n";

    $isi = $xlsx([
        PeriodClosingController::IMPORT_HEADER,
        [$aset->code,  $aset->name,  '1500000', '0', 'Kas awal'],
        [$modal->code, $modal->name, '0', '1500000', 'Modal awal'],
    ]);
    $resp = $impor('saldo-awal.xlsx', $isi, $TAHUN);
    $ok('import berhasil', $pesan($resp, 'error') === '', $pesan($resp, 'error'));

    $j = BsJournal::where('reference', 'SALDOAWAL/'.$TAHUN)->where('source', 'saldo_awal')->first();
    $ok('jurnal saldo awal terbentuk', $j !== null);
    $ok('sumbernya saldo_awal', $j && $j->source === 'saldo_awal', (string) $j?->source);

    /* Inilah dua hal yang membuat angkanya benar-benar muncul di halaman Input Saldo Awal. */
    $ok('bertanggal 31 Desember tahun sebelumnya',
        $j && $j->journal_date->toDateString() === sprintf('%04d-12-31', $TAHUN - 1),
        (string) $j?->journal_date?->toDateString());
    $ok("statusnya 'posted' — kalau 'final', halaman Saldo Awal tetap kosong",
        $j && $j->status === 'posted', (string) $j?->status);

    $ok('dua baris jurnal', $j && $j->lines()->count() === 2, (string) $j?->lines()->count());
    $ok('total debit = total kredit = 1.500.000',
        $j && $rp($j->total_debit) === 1500000 && $rp($j->total_credit) === 1500000,
        json_encode([$j?->total_debit, $j?->total_credit]));
    $ok('keterangan baris ikut terbawa',
        $j && str_contains($j->lines()->pluck('description')->implode(' | '), 'Kas awal'),
        (string) $j?->lines()->pluck('description')->implode(' | '));

    echo "\n=== 2. ANGKANYA MUNCUL DI HALAMAN INPUT SALDO AWAL ===\n";

    /* Bukti akhirnya bukan "jurnalnya ada", melainkan "angkanya tampil di halaman yang diimpor". */
    // Header X-Inertia wajib: tanpanya Inertia merender HTML penuh, bukan JSON berprop.
    $req = Request::create('/x', 'GET', ['year' => $TAHUN], [], [], ['HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $req->setUserResolver(fn () => $user);
    app()->instance('request', $req);
    $props = $ctrl->saldoAwalJurnal($req)->toResponse($req)->getData(true)['props'];

    $kode = collect($props['lines'])->pluck('code');
    $ok('akun aset tampil di halaman tahun '.$TAHUN, $kode->contains($aset->code), $kode->implode(', '));
    $ok('total debit halaman BERTAMBAH persis 1.500.000',
        $rp((float) $props['totals']['debit'] - $debitSebelum) === 1500000,
        json_encode(['sebelum' => $debitSebelum, 'sesudah' => $props['totals']['debit']]));

    echo "\n=== 3. IMPORT KEDUA DITOLAK ===\n";

    $resp = $impor('saldo-awal.xlsx', $isi, $TAHUN);
    $ok('import kedua ditolak', str_contains($pesan($resp, 'error'), 'sudah pernah diimpor'), $pesan($resp, 'error'));
    $ok('tidak lahir jurnal saldo awal kedua',
        BsJournal::where('reference', 'SALDOAWAL/'.$TAHUN)->where('source', 'saldo_awal')->count() === 1,
        (string) BsJournal::where('reference', 'SALDOAWAL/'.$TAHUN)->where('source', 'saldo_awal')->count());

    echo "\n=== 4. TIDAK BALANCE → BATAL SELURUHNYA ===\n";

    $TH2 = $TAHUN + 1;
    $svcJurnal->ensurePeriod($TH2 - 1, 12);
    $jumlahSebelum = BsJournal::count();

    $timpang = $xlsx([
        PeriodClosingController::IMPORT_HEADER,
        [$aset->code,  $aset->name,  '1000000', '0', 'Kas'],
        [$modal->code, $modal->name, '0', '900000', 'Modal'],
    ]);
    $resp = $impor('timpang.xlsx', $timpang, $TH2);
    $ok('selisih debit/kredit ditolak', str_contains($pesan($resp, 'error'), 'tidak sama dengan total Kredit'), $pesan($resp, 'error'));
    $ok('selisihnya disebutkan angkanya', str_contains($pesan($resp, 'error'), '100.000'), $pesan($resp, 'error'));
    $ok('tidak ada jurnal yang tersimpan', BsJournal::count() === $jumlahSebelum,
        (BsJournal::count() - $jumlahSebelum).' jurnal baru');

    echo "\n=== 5. BARIS BERMASALAH MEMBATALKAN SELURUH BERKAS ===\n";

    /* Berbeda dari import lain yang melewati baris jelek: melewatinya di sini membuat jurnal saldo
       awalnya timpang, dan itu justru yang paling mahal untuk ditemukan belakangan. */
    $adaSalah = $xlsx([
        PeriodClosingController::IMPORT_HEADER,
        [$aset->code,   $aset->name,  '1000000', '0', 'Kas'],
        ['KODE-NGAWUR', 'Entah',      '0', '1000000', 'Akun tak ada'],
    ]);
    $resp = $impor('salah.xlsx', $adaSalah, $TH2);
    $ok('akun tak dikenal membatalkan import', str_contains($pesan($resp, 'error'), 'Import dibatalkan'), $pesan($resp, 'error'));
    $ok('nomor barisnya disebutkan', str_contains($pesan($resp, 'error'), 'Baris 3'), $pesan($resp, 'error'));
    $ok('tidak ada jurnal yang tersimpan', BsJournal::count() === $jumlahSebelum,
        (BsJournal::count() - $jumlahSebelum).' jurnal baru');

    echo "\n=== 6. SATU AKUN TERISI DEBIT DAN KREDIT SEKALIGUS ===\n";

    $dobel = $xlsx([
        PeriodClosingController::IMPORT_HEADER,
        [$aset->code,  $aset->name,  '1000000', '500000', 'Dua-duanya'],
        [$modal->code, $modal->name, '0', '500000', 'Modal'],
    ]);
    $resp = $impor('dobel.xlsx', $dobel, $TH2);
    $ok('baris berisi Debit DAN Kredit ditolak', str_contains($pesan($resp, 'error'), 'Debit DAN Kredit'), $pesan($resp, 'error'));

    echo "\n=== 7. CSV JUGA DITERIMA, .xls DITOLAK ===\n";

    $csv = "KODE_AKUN,NAMA_AKUN,DEBIT,KREDIT,KETERANGAN\n"
        .$aset->code.','.$aset->name.",2000000,0,Kas awal\n"
        .$modal->code.','.$modal->name.",0,2000000,Modal awal\n";
    $resp = $impor('saldo-awal.csv', $csv, $TH2);
    $ok('berkas .csv diterima', $pesan($resp, 'error') === '', $pesan($resp, 'error'));
    $ok('jurnalnya terbentuk dari CSV',
        BsJournal::where('reference', 'SALDOAWAL/'.$TH2)->where('source', 'saldo_awal')->exists());

    $TH3 = $TAHUN + 2;
    $svcJurnal->ensurePeriod($TH3 - 1, 12);
    $resp = $impor('lama.xls', $isi, $TH3);
    $ok('berkas .xls ditolak dengan jelas', str_contains($pesan($resp, 'error'), '.xls lama tidak didukung'), $pesan($resp, 'error'));

    echo "\n=== 8. TEMPLATE .xlsx SUNGGUHAN ===\n";

    $reqT = Request::create('/x', 'GET');
    $reqT->setUserResolver(fn () => $user);
    app()->instance('request', $reqT);
    $tpl = $ctrl->saldoAwalTemplate($reqT);
    $bin = $tpl->getContent();

    $ok('template berupa arsip OOXML (bukan HTML berlabel Excel)', str_starts_with($bin, 'PK'), substr($bin, 0, 8));
    $ok('Content-Type spreadsheetml',
        str_contains((string) $tpl->headers->get('Content-Type'), 'spreadsheetml'),
        (string) $tpl->headers->get('Content-Type'));

    /* Template harus benar-benar terbaca oleh pembaca yang sama yang dipakai import — template
       yang tidak bisa dibaca sendiri adalah cacat yang baru ketahuan di tangan pengguna. */
    $tmpT = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
    file_put_contents($tmpT, $bin);
    $barisTpl = SimpleXlsx::rows($tmpT);
    $ok('template terbaca kembali oleh SimpleXlsx', count($barisTpl) >= 2, (string) count($barisTpl));
    $ok('judul kolomnya sesuai',
        array_map('strval', $barisTpl[0] ?? []) === PeriodClosingController::IMPORT_HEADER,
        json_encode($barisTpl[0] ?? []));
    @unlink($tmpT);

    echo "\n──────────────────────────────────────────────────────────────────────────────\n";
    echo $gagal === 0 ? "SEMUA LULUS ($lulus)\n" : "ADA YANG GAGAL — lulus $lulus, gagal $gagal\n";
} finally {
    DB::rollBack();
    echo "(data uji dibatalkan)\n";
}

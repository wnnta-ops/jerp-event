<?php
// Link berkas supplier: tetap menerima unggahan ulang sampai berkas di-TTD penuh; sesudah itu nonaktif
// bagi supplier, pembuat invoice masih bisa menambah file. Jalankan: php artisan tinker tests/Manual/uji-link-tutup-ttd.php
use App\Domain\BusinessSupport\Models\BsApBerkasLink;
use App\Domain\BusinessSupport\Models\BsApBerkasUpload;
use App\Domain\BusinessSupport\Services\ApBerkasService;
use App\Domain\BusinessSupport\Services\LinkBerkasSupplier;
use App\Exceptions\BusinessRuleException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$gagal = 0; $lulus = 0;
$cek = function (string $nama, bool $ok) use (&$gagal, &$lulus) { $ok ? $lulus++ : $gagal++; echo ($ok ? '  ✓ ' : '  ✗ GAGAL ').$nama."\n"; };
Storage::fake(ApBerkasService::DISK);
DB::beginTransaction();
try {
    $link = BsApBerkasLink::query()->where('status', 'terisi')->whereNotNull('berkas_upload_id')->latest('id')->firstOrFail();
    $berkas = BsApBerkasUpload::findOrFail($link->berkas_upload_id);
    $berkas->update(['status' => BsApBerkasUpload::STATUS_MENUNGGU_TTD]);
    $link->update(['riwayat' => null]);
    $link->refresh();

    $cek('riwayat kosong → tetap 1 entri dari filled_at', count($link->riwayatUnggahan()) === 1);
    $cek('menunggu TTD → link menerima unggahan', $link->menerimaUnggahan());

    $berkas->update(['status' => BsApBerkasUpload::STATUS_SIAP_INVOICE]);
    $link->refresh();
    $cek('TTD selesai → link nonaktif bagi supplier', ! $link->menerimaUnggahan() && $link->aktif());
    $jenis = \App\Domain\BusinessSupport\Models\BsApBerkas::jenisTetap()->first();
    $file = fn () => [['file' => UploadedFile::fake()->create('tambahan.pdf', 50, 'application/pdf'), 'berkas_id' => $jenis->id, 'keterangan' => null]];
    try {
        app(LinkBerkasSupplier::class)->terimaDariSupplier($link, $file(), null, 'X-1');
        $cek('supplier ditolak mengunggah', false);
    } catch (BusinessRuleException $e) {
        $cek('supplier ditolak mengunggah', $e->errorCode() === 'LINK_TUTUP');
    }

    $pemilik = \App\Models\User::findOrFail($berkas->uploaded_by);
    $sebelum = $berkas->files()->count();
    app(ApBerkasService::class)->tambahFileSesudahTtd($berkas, $file(), $pemilik);
    $cek('pemilik bisa menambah file sesudah TTD', $berkas->files()->count() === $sebelum + 1);
    $cek('status berkas tetap Siap Invoice', $berkas->fresh()->status === BsApBerkasUpload::STATUS_SIAP_INVOICE);

    $lain = \App\Models\User::query()->whereKeyNot($pemilik->id)->first();
    try {
        app(ApBerkasService::class)->tambahFileSesudahTtd($berkas, $file(), $lain);
        $cek('user lain ditolak', false);
    } catch (BusinessRuleException $e) {
        $cek('user lain ditolak', $e->errorCode() === 'BERKAS_BUKAN_MILIK');
    }

    $berkas->update(['status' => BsApBerkasUpload::STATUS_MENUNGGU_TTD]);
    try {
        app(ApBerkasService::class)->tambahFileSesudahTtd($berkas->fresh(), $file(), $pemilik);
        $cek('belum TTD → tambah file lewat jalur ini ditolak', false);
    } catch (BusinessRuleException $e) {
        $cek('belum TTD → tambah file lewat jalur ini ditolak', $e->errorCode() === 'BERKAS_LOCKED');
    }
} finally {
    DB::rollBack();
}
echo $gagal ? "ADA YANG GAGAL ({$gagal})\n" : "SEMUA LULUS ({$lulus})\n";
exit(0);

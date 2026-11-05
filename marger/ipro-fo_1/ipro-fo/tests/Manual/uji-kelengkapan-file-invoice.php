<?php
// Kelengkapan Berkas di rincian Invoice Hutang: unggah file seperti di pengajuan. Invoice dari berkas →
// file masuk ke berkas itu; invoice tanpa berkas → lampiran invoice. Hanya file tambahan yang bisa dihapus.
// Jalankan: php artisan tinker tests/Manual/uji-kelengkapan-file-invoice.php </dev/null
use App\Domain\BusinessSupport\Models\BsApBerkas;
use App\Domain\BusinessSupport\Models\BsApBerkasUpload;
use App\Domain\BusinessSupport\Models\BsApBerkasUploadFile;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Services\ApBerkasService;
use App\Domain\BusinessSupport\Services\KelengkapanBerkasInvoice;
use App\Exceptions\BusinessRuleException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$gagal = 0; $lulus = 0;
$cek = function (string $nama, bool $ok) use (&$gagal, &$lulus) { $ok ? $lulus++ : $gagal++; echo ($ok ? '  ✓ ' : '  ✗ GAGAL ').$nama."\n"; };
Storage::fake(ApBerkasService::DISK);
DB::beginTransaction();
try {
    $svc = app(KelengkapanBerkasInvoice::class);
    $user = \App\Models\User::findOrFail(1);
    auth()->setUser($user);
    $jenis = BsApBerkas::jenisTetap()->first();
    $file = fn (string $n = 'faktur.pdf') => ['file' => UploadedFile::fake()->create($n, 40, 'application/pdf'), 'berkas_id' => $jenis->id, 'keterangan' => null];

    // 1. Invoice dari berkas → file masuk berkas.
    $invB = BsApInvoice::query()->where('source_type', ApBerkasService::INVOICE_SOURCE)->whereNotNull('source_id')->latest('id')->firstOrFail();
    $berkas = BsApBerkasUpload::findOrFail($invB->source_id);
    $asli = $berkas->files()->count();
    $cek('daftar memuat file berkas asli', count($svc->ringkas($invB)) === $asli);
    $svc->tambah($invB, [$file('po.pdf'), $file('spk.pdf')], $user);
    $cek('2 file masuk ke berkas invoice', $berkas->files()->count() === $asli + 2);
    $r = $svc->ringkas($invB);
    $cek('ringkas: file asli bukan tambahan, file baru tambahan', collect($r)->where('tambahan', true)->count() === 2 && collect($r)->where('tambahan', false)->count() === $asli);
    $cek('tercatat pengunggah', collect($r)->firstWhere('tambahan', true)['oleh'] === $user->name);
    $baru = BsApBerkasUploadFile::where('ap_invoice_id', $invB->id)->first();
    Storage::disk(ApBerkasService::DISK)->assertExists($baru->path);
    $cek('file tersimpan di folder berkas', str_starts_with($baru->path, "ap-berkas/{$berkas->tenant_id}/{$berkas->id}/"));
    $cek('timeline berkas mencatat file_ditambah', \App\Domain\Foundation\Audit\Models\AuditLog::where('auditable_id', $berkas->id)->where('event', 'berkas.file_ditambah')->where('new_values', 'like', '%'.str_replace('/', '%', $invB->invoice_number).'%')->exists());

    if ($asli > 0) {
        try { $svc->hapus($invB, $berkas->files()->whereNull('ap_invoice_id')->first()); $cek('file berkas asli tidak bisa dihapus', false); }
        catch (BusinessRuleException $e) { $cek('file berkas asli tidak bisa dihapus', $e->errorCode() === 'BERKAS_LOCKED'); }
    }
    $svc->hapus($invB, $baru);
    $cek('file tambahan terhapus (DB + disk)', ! BsApBerkasUploadFile::whereKey($baru->id)->exists());
    Storage::disk(ApBerkasService::DISK)->assertMissing($baru->path);

    // 2. Invoice tanpa berkas → lampiran invoice.
    $invL = BsApInvoice::query()->where(fn ($q) => $q->whereNull('source_type')->orWhere('source_type', '!=', ApBerkasService::INVOICE_SOURCE))->latest('id')->firstOrFail();
    $svc->tambah($invL, [$file('kuitansi.pdf')], $user);
    $f = BsApBerkasUploadFile::where('ap_invoice_id', $invL->id)->first();
    $cek('tanpa berkas → disimpan sebagai lampiran invoice', $f && $f->berkas_upload_id === null && str_starts_with($f->path, "ap-invoice-berkas/"));
    $cek('lampiran tampil di daftar', count($svc->ringkas($invL)) === 1);
    $cek('milikInvoice: file invoice lain ditolak', ! $svc->milikInvoice($invB, $f) && $svc->milikInvoice($invL, $f));
    try { $svc->hapus($invB, $f); $cek('hapus lewat invoice lain ditolak', false); }
    catch (BusinessRuleException $e) { $cek('hapus lewat invoice lain ditolak', true); }

    // 3. Batas jumlah file.
    try { $svc->tambah($invL, array_map(fn ($i) => $file("x{$i}.pdf"), range(1, KelengkapanBerkasInvoice::MAX_FILES)), $user); $cek('lebih dari batas ditolak', false); }
    catch (BusinessRuleException $e) { $cek('lebih dari batas ditolak', $e->errorCode() === 'BERKAS_TOO_MANY'); }

    // 4. HTTP: buka file + props rincian.
    $res = app()->handle(\Illuminate\Http\Request::create(route('bs.ap.kelengkapan-file.show', [$invL->id, $f->id], false)));
    $cek('route buka file 200', $res->getStatusCode() === 200);
    $res = app()->handle(\Illuminate\Http\Request::create(route('bs.ap.kelengkapan-file.show', [$invB->id, $f->id], false)));
    $cek('buka file lewat invoice lain → 404', $res->getStatusCode() === 404);
} finally {
    DB::rollBack();
}
echo $gagal ? "ADA YANG GAGAL ({$gagal})\n" : "SEMUA LULUS ({$lulus})\n";
exit(0);

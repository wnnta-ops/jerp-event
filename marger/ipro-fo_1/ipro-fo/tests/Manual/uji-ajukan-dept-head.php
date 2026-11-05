<?php
// Registrasi Invoice Hutang › TTD Dept Head: upload tidak langsung diajukan; "Ajukan ke Dept Head" (langkah
// Struktur) menyimpan draft + mengajukan berkas; disetujui → notifikasi ke pembuat + panel Draft;
// pembuat yang Dept Head → TTD otomatis; TTD Dept Head tampil di Memo Internal.
// Jalankan: php artisan tinker tests/Manual/uji-ajukan-dept-head.php </dev/null
use App\Domain\Approval\Actions\ActOnApproval;
use App\Domain\Approval\Models\Approval;
use App\Domain\BusinessSupport\Models\BsApBerkas;
use App\Domain\BusinessSupport\Models\BsApBerkasUpload;
use App\Domain\BusinessSupport\Models\BsApInvoiceDraft;
use App\Domain\BusinessSupport\Services\ApBerkasService;
use App\Domain\BusinessSupport\Services\LinkBerkasSupplier;
use App\Domain\BusinessSupport\Services\PengajuanDeptHead;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$gagal = 0; $lulus = 0;
$cek = function (string $nama, bool $ok) use (&$gagal, &$lulus) { $ok ? $lulus++ : $gagal++; echo ($ok ? '  ✓ ' : '  ✗ GAGAL ').$nama."\n"; };
Storage::fake(ApBerkasService::DISK);
DB::beginTransaction();
try {
    $dh = app(PengajuanDeptHead::class);
    $staf = User::where('email', 'staff@jerp.test')->firstOrFail();
    $kepala = User::where('email', 'pengaju@jerp.test')->firstOrFail();
    $cek('staf bukan Dept Head', ! $dh->isDeptHead($staf));
    $cek('pengaju@ (atasan karyawan aktif) = Dept Head', $dh->isDeptHead($kepala));

    $supplier = DB::table('bs_suppliers')->where('is_active', true)->value('id');
    $data = ['supplier_id' => $supplier, 'supplier_invoice_no' => 'UJI-DH-1', 'description' => 'Uji TTD Dept Head', 'dpp' => 1500000,
        'invoice_date' => now()->toDateString(), 'ppn_mode' => 'none'];
    $files = fn () => BsApBerkas::jenisTetap()->where('is_mandatory', true)->values()->map(fn ($j) => ['file' => UploadedFile::fake()->create('f'.$j->id.'.pdf', 30, 'application/pdf'), 'berkas_id' => $j->id, 'keterangan' => null])->all()
        ?: [['file' => UploadedFile::fake()->create('inv.pdf', 30, 'application/pdf'), 'berkas_id' => BsApBerkas::jenisTetap()->first()->id, 'keterangan' => null]];

    // 1. Upload File → berkas draft, BELUM diajukan.
    auth()->setUser($staf);
    $berkas = app(LinkBerkasSupplier::class)->upload($staf, $data, $files());
    $cek('upload → status draft (tidak langsung ke Dept Head)', $berkas->status === BsApBerkasUpload::STATUS_DRAFT && $berkas->approval_id === null);

    // 2. Simpan Draft dua kali → satu draft bebas.
    BsApInvoiceDraft::where('user_id', $staf->id)->delete();
    $d1 = $dh->simpanDraft($staf, $data + ['berkas_upload_id' => $berkas->id], 2);
    $d2 = $dh->simpanDraft($staf, $data + ['berkas_upload_id' => $berkas->id, 'description' => 'ubah'], 3);
    $cek('simpan draft berulang → draft yang sama', $d1->id === $d2->id && BsApInvoiceDraft::where('user_id', $staf->id)->count() === 1);

    // 3. Ajukan ke Dept Head.
    ['berkas' => $b, 'draft' => $d] = $dh->ajukan($staf, $data + ['berkas_upload_id' => $berkas->id], 4, $d1->id);
    $cek('ajukan → berkas menunggu TTD', $b->status === BsApBerkasUpload::STATUS_MENUNGGU_TTD && (int) $b->dept_head_id === (int) $kepala->id);
    $cek('ajukan → isian tersimpan di draft yang dibuka (langkah 4)', $d->id === $d1->id && $d->fresh()->step === 4);
    $cek('draft yang sudah diajukan bukan lagi draft bebas', $dh->draftBebas($staf) === null);
    $baru = $dh->simpanDraft($staf, ['description' => 'invoice lain'], 1);
    $cek('invoice lain → draft baru (2 draft)', $baru->id !== $d1->id && BsApInvoiceDraft::where('user_id', $staf->id)->count() === 2);
    $m = $dh->menunggu($staf);
    $cek('panel Draft: 1 menunggu TTD Dept Head', count($m) === 1 && $m[0]['status'] === 'menunggu' && str_contains($m[0]['url'], 'draft='.$d1->id));

    // 4. Dept Head menyetujui → siap, notifikasi ke pembuat menuju draftnya.
    auth()->setUser($kepala);
    app(ActOnApproval::class)->execute(Approval::findOrFail($b->approval_id), 'approve', 'ok');
    $cek('disetujui → berkas siap invoice', $b->fresh()->status === BsApBerkasUpload::STATUS_SIAP_INVOICE);
    $notif = DB::table('notifications')->where('notifiable_id', $staf->id)->latest('created_at')->get()
        ->first(fn ($n) => str_contains((string) $n->data, 'Berkas di-TTD Dept Head'));
    $cek('notifikasi ke pembuat → membuka draftnya', $notif !== null && str_contains(stripslashes((string) $notif->data), 'draft='.$d1->id));
    $cek('panel Draft: status disetujui', ($dh->menunggu($staf)[0]['status'] ?? null) === 'disetujui');

    // 5. Pembuat = Dept Head → TTD otomatis.
    auth()->setUser($kepala);
    $bk = app(LinkBerkasSupplier::class)->upload($kepala, $data, $files());
    $bk = $dh->ttdSendiri($bk, $kepala);
    $ap = Approval::with('steps')->find($bk->approval_id);
    $cek('Dept Head → berkas langsung siap invoice', $bk->status === BsApBerkasUpload::STATUS_SIAP_INVOICE);
    $cek('TTD otomatis tercatat atas namanya', $ap?->status === 'approved' && $ap->steps->every(fn ($s) => $s->status === 'approved' && (int) $s->approver_id === (int) $kepala->id));
    $cek('tidak muncul di inbox siapa pun', ! DB::table('notifications')->where('data', 'like', '%'.$bk->berkas_number.'%')->where('data', 'like', '%Menunggu persetujuan%')->exists());
    try { $dh->ajukan($staf, $data + ['berkas_upload_id' => $bk->id], 4); $cek('ajukan berkas orang lain ditolak', false); }
    catch (\App\Exceptions\BusinessRuleException $e) { $cek('ajukan berkas orang lain ditolak', $e->errorCode() === 'BERKAS_BUKAN_MILIK'); }

    // 6. Memo Internal: TTD Dept Head dari approval berkas.
    $inv = new \App\Domain\BusinessSupport\Models\BsApInvoice(['source_type' => ApBerkasService::INVOICE_SOURCE]);
    $inv->source_id = $b->id;
    $ref = new ReflectionMethod(\App\Http\Controllers\Web\BusinessSupport\Payable\ApInvoiceController::class, 'ttdDeptHeadMemo');
    $t = $ref->invoke(app(\App\Http\Controllers\Web\BusinessSupport\Payable\ApInvoiceController::class), $inv);
    $cek('Memo Internal: TTD Dept Head = yang menyetujui berkas', ($t['nama'] ?? null) === $kepala->name);
} finally {
    DB::rollBack();
}
echo $gagal ? "ADA YANG GAGAL ({$gagal})\n" : "SEMUA LULUS ({$lulus})\n";
exit(0);

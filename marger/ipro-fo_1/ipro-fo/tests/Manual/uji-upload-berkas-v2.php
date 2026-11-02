<?php

declare(strict_types=1);

/*
 * Uji Upload Berkas v2 — siklus dokumen lengkap & aturan tambahan.
 *
 *   · Draft: tersimpan tanpa approval; bisa diubah; perubahan tercatat di audit.
 *   · Dept Head otomatis = atasan pengunggah (Employee Center); nomor DOC-{tgl}-{supplier}-{urut}
 *     ikut berganti bila tanggal tagihan / supplier draft diubah.
 *   · Ajukan: berkas wajib (master) harus lengkap; MOU & Bukti Serah Terima ada di checklist, tidak wajib.
 *   · Ditolak → revisi → ajukan ulang (approval TTD baru).
 *   · Batal dengan alasan (TTD yang menunggu ikut batal) · tidak bisa batal setelah ada invoice.
 *   · Invoice bertahap: sebagian → tidak boleh melebihi sisa → tutup parsial.
 *   · Hapus hanya draft/ditolak/dibatalkan.
 *   · Pengaju tidak menyetujui dokumennya sendiri (invoice).
 *   · Notifikasi lonceng ke approver berikutnya, pengunggah, dan tim AP.
 *
 * Disk 'local' dipalsukan dan data DB DIBATALKAN (rollback).
 *
 * Jalankan:  php tests/Manual/uji-upload-berkas-v2.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\Approval\Actions\ActOnApproval;
use App\Domain\Approval\Models\Approval;
use App\Domain\ApprovalInbox\Services\ApprovalInboxQuery;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Services\ApBerkasService;
use App\Domain\Foundation\Audit\Models\AuditLog;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

$tenantId = (int) DB::table('tenants')->orderBy('id')->value('id');
app()->instance('tenant.id', $tenantId);

$lulus = 0;
$gagal = 0;
$cek = function (string $nama, bool $ok, string $ket = '') use (&$lulus, &$gagal) {
    if ($ok) {
        $lulus++;
        echo "  OK    {$nama}\n";
    } else {
        $gagal++;
        echo "  GAGAL {$nama}".($ket !== '' ? " — {$ket}" : '')."\n";
    }
};
$kode = function (callable $fn): ?string {
    try {
        $fn();
    } catch (BusinessRuleException $e) {
        return $e->errorCode();
    }

    return null;
};

Storage::fake('local');
DB::beginTransaction();

try {
    $buatUser = function (string $nama, array $roles) use ($tenantId): User {
        $u = User::create([
            'tenant_id' => $tenantId, 'name' => $nama, 'username' => 'v'.Str::random(5),
            'email' => Str::slug($nama).'.'.Str::random(6).'@uji.test',
            'password' => bcrypt(Str::random(16)), 'is_active' => true,
        ]);
        $u->syncRoles($roles);

        return $u->fresh();
    };
    $staf = $buatUser('Staf V2 Uji', ['Collection']);
    $dh = $buatUser('Dept Head V2 Uji', ['DeptHead']);
    $acc = $buatUser('Accounting V2 Uji', ['Accounting']);
    $fin = $buatUser('Finance V2 Uji', ['Finance']);
    $karyawan = function (User $u, ?User $atasan = null) use ($tenantId): void {
        $nik = 'UJI-'.Str::upper(Str::random(8));
        DB::table('bs_employees')->insert([
            'tenant_id' => $tenantId, 'nik' => $nik, 'name' => $u->name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            'atasan_id' => $atasan ? DB::table('bs_employees')->where('nik', $atasan->employee_nip)->value('id') : null,
        ]);
        $u->forceFill(['employee_nip' => $nik])->save();
    };
    $karyawan($dh);
    $karyawan($staf, $dh);
    $svc = app(ApBerkasService::class);
    $act = app(ActOnApproval::class);
    $inbox = app(ApprovalInboxQuery::class);
    $sebagai = fn (User $u) => auth()->setUser($u);
    $muat = fn (int $id) => Approval::with(['steps', 'approvable'])->findOrFail($id);
    $wajib = DB::table('bs_ap_berkas')->where('is_active', true)->where('is_mandatory', true)->pluck('id');
    $fileWajib = fn () => $wajib->map(fn ($id) => ['file' => UploadedFile::fake()->create("w{$id}.pdf", 50, 'application/pdf'), 'berkas_id' => (int) $id])->all();
    $fileLain = fn () => [['file' => UploadedFile::fake()->create('lain.pdf', 50, 'application/pdf'), 'berkas_id' => null]];
    $data = fn (array $x = []) => $x + ['upload_date' => now()->toDateString(), 'description' => 'Tagihan v2', 'document_date' => '2026-04-20', 'amount' => 11100000];
    $notif = fn (User $u, string $type) => DB::table('notifications')->where('notifiable_id', $u->id)->where('type', $type)->count();
    $sup = (int) DB::table('bs_suppliers')->value('id');
    $akun = (int) DB::table('bs_accounts')->where('code', '50302020219')->value('id');
    $invoice = fn (float $total) => BsApInvoice::create([
        'tenant_id' => $tenantId, 'invoice_number' => 'INV-V2-'.Str::random(6), 'invoice_date' => now()->toDateString(),
        'supplier_id' => $sup, 'expense_account_id' => $akun, 'payable_account_id' => $akun, 'description' => 'Invoice v2',
        'dpp' => $total, 'total' => $total, 'net_payable' => $total, 'paid_amount' => 0, 'status' => 'open',
    ]);

    echo "\n=== 1. Dept Head otomatis & nomor ===\n";
    $sebagai($staf);
    $cek('Dept Head = atasan pengunggah', (int) $svc->deptHeadUntuk($staf)?->id === (int) $dh->id);
    $cek('Dept Head sendiri (tanpa atasan) → tidak ada', $svc->deptHeadUntuk($dh) === null);

    echo "\n=== 2. Draft & edit ===\n";
    $b = $svc->create($data(), $fileLain(), $staf);
    $cek('tersimpan sebagai draft tanpa approval', $b->status === 'draft' && $b->approval_id === null);
    $cek('Dept Head terisi otomatis', (int) $b->dept_head_id === (int) $dh->id);
    $cek('nomor DOC-200426-NA-…', str_starts_with($b->berkas_number, 'DOC-200426-NA-'), $b->berkas_number);
    $cek('total tagihan tersimpan (11.100.000)', (float) $b->amount === 11100000.0, (string) $b->amount);
    $supKode = DB::table('bs_suppliers')->orderBy('id')->first(['id', 'code']);
    $svc->update($b, $data(['supplier_id' => $supKode->id, 'document_date' => '2026-04-21']), $staf);
    $cek('ganti supplier & tgl tagihan → nomor ikut berganti', str_starts_with($b->fresh()->berkas_number, 'DOC-210426-'.preg_replace('/[^A-Z0-9]/', '', strtoupper($supKode->code)).'-'), $b->fresh()->berkas_number);
    $no = $b->fresh()->berkas_number;
    $svc->update($b->fresh(), $data(['supplier_id' => $supKode->id, 'document_date' => '2026-04-21', 'description' => 'x']), $staf);
    $cek('isian lain berubah → nomor tetap', $b->fresh()->berkas_number === $no);
    $svc->update($b, $data(['description' => 'Tagihan v2 diubah']), $staf);
    $cek('isian draft bisa diubah', $b->fresh()->description === 'Tagihan v2 diubah');
    $cek('perubahan tercatat di audit (updated)', AuditLog::where('auditable_id', $b->id)->where('auditable_type', 'ap_berkas_upload')->where('event', 'updated')->exists());

    echo "\n=== 3. Ajukan: berkas wajib ===\n";
    $cek('tanpa berkas wajib → ditolak', $kode(fn () => $svc->submit($b->fresh(), $staf)) === 'BERKAS_WAJIB');
    $svc->addFiles($b->fresh(), $fileWajib(), $staf);
    $svc->submit($b->fresh(), $staf);
    $b->refresh();
    $cek('setelah lengkap → menunggu TTD', $b->status === 'menunggu_ttd' && $b->approval_id !== null);
    $cek('Dept Head dapat notifikasi', $notif($dh, 'approval.menunggu') === 1);
    $cek('setelah diajukan isian terkunci', $kode(fn () => $svc->update($b->fresh(), $data(), $staf)) === 'BERKAS_LOCKED');
    $cek('setelah diajukan file terkunci', $kode(fn () => $svc->addFiles($b->fresh(), $fileLain(), $staf)) === 'BERKAS_LOCKED');

    echo "\n=== 4. Ditolak → revisi → ajukan ulang ===\n";
    $sebagai($dh);
    $act->execute($muat($b->approval_id), 'reject', 'Lampirkan BAST');
    $cek('berkas ditolak', $b->fresh()->status === 'ditolak');
    $cek('pengunggah dapat notifikasi ditolak', $notif($staf, 'approval.ditolak') === 1);
    $sebagai($staf);
    $lama = (int) $b->fresh()->approval_id;
    $svc->update($b->fresh(), $data(['description' => 'Tagihan v2 revisi']), $staf);
    $svc->submit($b->fresh(), $staf);
    $cek('ajukan ulang → approval TTD baru', $b->fresh()->status === 'menunggu_ttd' && (int) $b->fresh()->approval_id !== $lama);
    $cek('riwayat mencatat diajukan ulang', AuditLog::where('auditable_id', $b->id)->where('event', 'berkas.diajukan_ulang')->exists());

    echo "\n=== 5. Batal ===\n";
    $c = $svc->create($data(), $fileWajib(), $staf, true);
    $svc->cancel($c, $staf, 'Tagihan dobel');
    $cek('berkas dibatalkan dengan alasan', $c->fresh()->status === 'dibatalkan' && $c->fresh()->cancel_reason === 'Tagihan dobel');
    $cek('TTD yang menunggu ikut batal', Approval::find($c->fresh()->approval_id)->status === 'cancelled');
    $cek('hilang dari inbox Dept Head', ! $inbox->needsMe($dh)->contains('id', $c->fresh()->approval_id));

    echo "\n=== 6. Invoice bertahap & tutup parsial ===\n";
    $sebagai($dh);
    $act->execute($muat($b->fresh()->approval_id), 'approve');
    $cek('TTD → siap invoice', $b->fresh()->status === 'siap_invoice');
    // Invoice dibuat oleh pengunggahnya sendiri → notifikasi siap invoice hanya ke pengunggah.
    $siapKe = DB::table('notifications')->where('type', 'berkas.siap_invoice')->where('created_at', '>=', now()->subMinute())->pluck('notifiable_id')->unique()->values()->all();
    $cek('pengunggah (bukan seluruh tim AP) dapat notifikasi siap invoice', $siapKe === [$staf->id], json_encode($siapKe));
    $sebagai($acc); // pembuat invoice = Accounting (bukan SuperAdmin)
    $ia = $svc->ajukanInvoice($b->fresh(), $invoice(4000000));
    $cek('invoice 4jt dari 11,1jt → sebagian diinvoice', $b->fresh()->status === 'sebagian_diinvoice', $b->fresh()->status);
    $cek('sisa 7,1jt', abs($b->fresh()->sisa() - 7100000) < 0.01, (string) $b->fresh()->sisa());
    $cek('invoice melebihi sisa ditolak', $kode(fn () => $svc->ajukanInvoice($b->fresh(), $invoice(8000000))) === 'BERKAS_OVER');
    $cek('tidak bisa batal setelah ada invoice', $kode(fn () => $svc->cancel($b->fresh(), $staf, 'x')) === 'BERKAS_NO_CANCEL');
    $cek('Accounting dapat notifikasi approval invoice', $notif($acc, 'approval.menunggu') >= 1);

    echo "\n=== 7. Pengaju tidak menyetujui sendiri ===\n";
    $cek('pembuat invoice (Accounting) tidak bisa approve tahap Accounting', $kode(fn () => $act->execute($muat($ia->id), 'approve')) === 'APPROVAL_SELF');
    $cek('tombol keputusan tidak tampil untuknya', ! $inbox->canAct($acc, $muat($ia->id)));
    $acc2 = $buatUser('Accounting Lain V2 Uji', ['Accounting']);
    $sebagai($acc2);
    $act->execute($muat($ia->id), 'approve');
    $cek('Accounting lain bisa approve', $muat($ia->id)->status === 'in_review');
    $cek('Finance dapat notifikasi langkah berikutnya', $notif($fin, 'approval.menunggu') >= 1);

    $sebagai($acc);
    $svc->close($b->fresh(), $acc, 'Sisa tagihan dibatalkan supplier');
    $cek('tutup parsial → ditutup', $b->fresh()->status === 'ditutup');
    $cek('setelah ditutup tidak bisa diinvoice lagi', $kode(fn () => $svc->assertSiapInvoice($b->fresh())) === 'BERKAS_NOT_READY');

    echo "\n=== 8. Hapus ===\n";
    $sebagai($staf);
    $d = $svc->create($data(), $fileLain(), $staf);
    $svc->delete($d, $staf);
    $cek('draft terhapus (soft delete, masih bisa dilihat)', App\Domain\BusinessSupport\Models\BsApBerkasUpload::withTrashed()->find($d->id)?->trashed() === true);
    $cek('berkas yang sudah ditutup tidak bisa dihapus', $kode(fn () => $svc->delete($b->fresh(), $staf)) === 'BERKAS_NO_DELETE');

    echo "\n=== 9. Checklist berkas ===\n";
    $tambahan = DB::table('bs_ap_berkas')->whereIn('code', ['FAKTUR-PAJAK', 'MOU', 'SERAH-TERIMA'])->where('is_active', true)->get(['code', 'is_mandatory']);
    $cek('Faktur Pajak, MOU, Bukti Serah Terima ada di master', $tambahan->count() === 3, $tambahan->pluck('code')->implode(','));
    $cek('ketiganya tidak wajib', $tambahan->every(fn ($j) => ! $j->is_mandatory));
    $cek('tanpa MOU/serah terima tetap bisa diajukan', $svc->berkasWajibKurang($svc->create($data(), $fileWajib(), $staf)) === []);
    $tanpaAtasan = $buatUser('Staf Tanpa Atasan V2 Uji', ['Collection']);
    $e = $svc->create($data(), $fileWajib(), $tanpaAtasan);
    $cek('tanpa atasan: draft tersimpan', $e->status === 'draft' && $e->dept_head_id === null);
    $cek('tanpa atasan: tidak bisa diajukan', $kode(fn () => $svc->submit($e, $tanpaAtasan)) === 'BERKAS_DEPT_HEAD');

    echo "\n".str_repeat('─', 78)."\n";
    echo $gagal === 0 ? "SEMUA LULUS ({$lulus})\n" : "GAGAL: {$gagal}, lulus: {$lulus}\n";
} catch (Throwable $e) {
    echo "\nGALAT: ".$e->getMessage()."\n  di ".$e->getFile().':'.$e->getLine()."\n";
    $gagal++;
} finally {
    DB::rollBack();
    echo "(data uji dibatalkan)\n";
}

exit($gagal === 0 ? 0 : 1);

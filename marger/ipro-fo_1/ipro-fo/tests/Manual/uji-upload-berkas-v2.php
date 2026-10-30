<?php

declare(strict_types=1);

/*
 * Uji Upload Berkas v2 — siklus dokumen lengkap & aturan tambahan.
 *
 *   · Draft: tersimpan tanpa approval; bisa diubah; perubahan tercatat di audit.
 *   · Ajukan: berkas wajib (master) harus lengkap; anggaran dicek bila akun+CC terisi.
 *   · Pajak: subtotal − diskon (+PPN, atau PPN informasi bila harga termasuk PPN); diskon ≤ subtotal.
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
    $svc = app(ApBerkasService::class);
    $act = app(ActOnApproval::class);
    $inbox = app(ApprovalInboxQuery::class);
    $sebagai = fn (User $u) => auth()->setUser($u);
    $muat = fn (int $id) => Approval::with(['steps', 'approvable'])->findOrFail($id);
    $wajib = DB::table('bs_ap_berkas')->where('is_active', true)->where('is_mandatory', true)->pluck('id');
    $fileWajib = fn () => $wajib->map(fn ($id) => ['file' => UploadedFile::fake()->create("w{$id}.pdf", 50, 'application/pdf'), 'berkas_id' => (int) $id])->all();
    $fileLain = fn () => [['file' => UploadedFile::fake()->create('lain.pdf', 50, 'application/pdf'), 'berkas_id' => null]];
    $data = fn (array $x = []) => $x + ['upload_date' => now()->toDateString(), 'description' => 'Tagihan v2', 'dept_head_id' => $dh->id, 'subtotal' => 10000000];
    $notif = fn (User $u, string $type) => DB::table('notifications')->where('notifiable_id', $u->id)->where('type', $type)->count();
    $sup = (int) DB::table('bs_suppliers')->value('id');
    $akun = (int) DB::table('bs_accounts')->where('code', '50302020219')->value('id');
    $invoice = fn (float $total) => BsApInvoice::create([
        'tenant_id' => $tenantId, 'invoice_number' => 'INV-V2-'.Str::random(6), 'invoice_date' => now()->toDateString(),
        'supplier_id' => $sup, 'expense_account_id' => $akun, 'payable_account_id' => $akun, 'description' => 'Invoice v2',
        'dpp' => $total, 'total' => $total, 'net_payable' => $total, 'paid_amount' => 0, 'status' => 'open',
    ]);

    echo "\n=== 1. Pajak & total ===\n";
    $t = ApBerkasService::hitungTotal(10000000, 1000000, 11, false);
    $cek('normal: (10jt − 1jt) + PPN 11% = 9.990.000', $t['amount'] == 9990000.0 && $t['ppn_amount'] == 990000.0, json_encode($t));
    $t = ApBerkasService::hitungTotal(11100000, 0, 11, true);
    $cek('termasuk PPN: total = subtotal, PPN informasi 1.100.000', $t['amount'] == 11100000.0 && $t['ppn_amount'] == 1100000.0, json_encode($t));
    $sebagai($staf);
    $cek('diskon > subtotal ditolak', $kode(fn () => $svc->create($data(['discount' => 20000000]), [], $staf)) === 'BERKAS_DISKON');

    echo "\n=== 2. Draft & edit ===\n";
    $b = $svc->create($data(['ppn_pct' => 11, 'jenis' => 'biaya_non_proyek']), $fileLain(), $staf);
    $cek('tersimpan sebagai draft tanpa approval', $b->status === 'draft' && $b->approval_id === null);
    $cek('total dihitung server (11.100.000)', (float) $b->amount === 11100000.0, (string) $b->amount);
    $svc->update($b, $data(['description' => 'Tagihan v2 diubah', 'ppn_pct' => 11]), $staf);
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
    $svc->update($b->fresh(), $data(['description' => 'Tagihan v2 revisi', 'ppn_pct' => 11]), $staf);
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
    $cek('tim AP (ap.invoice.create) dapat notifikasi siap invoice', DB::table('notifications')->where('type', 'berkas.siap_invoice')->exists());
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

    echo "\n=== 9. Cek anggaran saat diajukan ===\n";
    $ccTanpa = (int) DB::table('bs_cost_centers')->where('code', 'CC-OPS')->value('id');
    $e = $svc->create($data(['expense_account_id' => $akun, 'cost_center_id' => $ccTanpa]), $fileWajib(), $staf);
    $tolak = false;
    try {
        $svc->submit($e, $staf);
    } catch (Throwable $ex) {
        $tolak = true;
    }
    $cek('akun × cost center tanpa anggaran → tidak bisa diajukan', $tolak && $e->fresh()->status === 'draft');

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

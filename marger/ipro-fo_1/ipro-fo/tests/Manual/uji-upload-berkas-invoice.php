<?php

declare(strict_types=1);

/*
 * Uji Upload Berkas → TTD Dept Head → Invoice Hutang → approval → bayar.
 *
 * Yang dijaga:
 *   · Nomor BRK-{USER}-{MMYY}-{NNNN} urut per pengunggah; file tersimpan di disk privat.
 *   · Penanda tangan harus ber-peran Dept Head dan bukan pengunggahnya sendiri; hanya
 *     Dept Head YANG DIPILIH yang melihat & bisa menandatangani (approver 'assigned').
 *   · Berkas baru bisa dijadikan invoice setelah TTD, dan hanya sekali.
 *   · Invoice dari berkas TIDAK bisa dibayar (bayar langsung maupun kuitansi) sampai approval
 *     Accounting → Finance → GM → Direktur → MW / BA selesai; invoice lama tetap bisa.
 *   · Tracker invoice memuat alur penuh: Pengunggah, Dept Head (TTD), Tim AP, lalu 5 tahap.
 *
 * Disk 'local' dipalsukan dan data DB DIBATALKAN (rollback).
 *
 * Jalankan:  php tests/Manual/uji-upload-berkas-invoice.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\Approval\Actions\ActOnApproval;
use App\Domain\Approval\Models\Approval;
use App\Domain\ApprovalInbox\Services\ApprovalInboxQuery;
use App\Domain\BusinessSupport\Actions\PayApInvoice;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Services\ApBerkasService;
use App\Domain\BusinessSupport\Services\ApKuitansiService;
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
            'tenant_id' => $tenantId, 'name' => $nama, 'username' => 'u'.Str::random(5),
            'email' => Str::slug($nama).'.'.Str::random(6).'@uji.test',
            'password' => bcrypt(Str::random(16)), 'is_active' => true,
        ]);
        $u->syncRoles($roles);

        return $u->fresh();
    };
    $staf = $buatUser('Staf Berkas Uji', ['Collection']);
    $dh = $buatUser('Dept Head Uji', ['DeptHead']);
    $dhLain = $buatUser('Dept Head Lain Uji', ['DeptHead']);
    $ap = $buatUser('Tim AP Uji', ['SuperAdmin']);
    $role = [];
    foreach (['Accounting', 'Finance', 'GM', 'Direktur', 'Owner'] as $r) {
        $role[$r] = $buatUser("Approver {$r} Berkas Uji", [$r]);
    }
    $svc = app(ApBerkasService::class);
    $inbox = app(ApprovalInboxQuery::class);
    $act = app(ActOnApproval::class);
    $sebagai = fn (User $u) => auth()->setUser($u);
    $muat = fn (int $id) => Approval::with(['steps', 'approvable'])->findOrFail($id);
    // v2: berkas wajib (master bs_ap_berkas.is_mandatory) harus ada — sertakan semua jenis wajib.
    $wajib = DB::table('bs_ap_berkas')->where('is_active', true)->where('is_mandatory', true)->pluck('id');
    $file = fn () => $wajib->map(fn ($id) => ['file' => UploadedFile::fake()->create("berkas-{$id}.pdf", 120, 'application/pdf'), 'berkas_id' => (int) $id])->all();
    $data = fn (array $x = []) => $x + ['upload_date' => now()->toDateString(), 'description' => 'Tagihan uji', 'dept_head_id' => $dh->id, 'amount' => 5000000];

    echo "\n=== 1. Unggah berkas ===\n";
    $sebagai($staf);
    $b = $svc->upload($data(), $file(), $staf);
    $cek('nomor BRK-{USER}-{MMYY}-0001', (bool) preg_match('#^BRK-[A-Z0-9]+-\d{4}-0001$#', $b->berkas_number), $b->berkas_number);
    $b2 = $svc->upload($data(), $file(), $staf);
    $cek('berkas kedua user sama → urut 0002', str_ends_with($b2->berkas_number, '-0002'), $b2->berkas_number);
    $cek('status menunggu_ttd', $b->status === 'menunggu_ttd');
    $cek('file tersimpan di disk privat', Storage::disk('local')->exists($b->files()->first()->path));
    $cek('Dept Head bukan peran DeptHead ditolak', $kode(fn () => $svc->upload($data(['dept_head_id' => $role['GM']->id]), $file(), $staf)) === 'BERKAS_DEPT_HEAD');
    $cek('Dept Head menandatangani berkasnya sendiri ditolak', $kode(fn () => $svc->upload($data(), $file(), $dh)) === 'BERKAS_SELF_SIGN');
    $cek('tanpa file ditolak', $kode(fn () => $svc->upload($data(), [], $staf)) === 'BERKAS_EMPTY');

    echo "\n=== 2. TTD Dept Head (approver ditunjuk) ===\n";
    $a = $muat($b->approval_id);
    $cek('Dept Head terpilih melihat di Perlu Persetujuan', $inbox->needsMe($dh)->contains('id', $a->id));
    $cek('Dept Head LAIN tidak melihat', ! $inbox->needsMe($dhLain)->contains('id', $a->id));
    $cek('belum TTD → belum masuk Riwayat Dept Head', ! $inbox->history($dh)->contains('id', $a->id));
    $sebagai($dhLain);
    $cek('Dept Head lain tidak bisa menandatangani', $kode(fn () => $act->execute($a, 'approve')) === 'APPROVAL_FORBIDDEN');
    $cek('belum TTD → belum bisa dibuat invoice', $kode(fn () => $svc->assertSiapInvoice($b->fresh())) === 'BERKAS_NOT_READY');
    $sebagai($dh);
    $act->execute($muat($b->approval_id), 'approve', 'Sesuai');
    $cek('setelah TTD → siap_invoice', $b->fresh()->status === 'siap_invoice');
    $cek('masuk Riwayat Dept Head', $inbox->history($dh)->contains('id', $a->id));

    echo "\n=== 3. Invoice dari berkas → approval ===\n";
    $sup = (int) DB::table('bs_suppliers')->value('id');
    $acc = (int) DB::table('bs_accounts')->value('id');
    $buatInvoice = fn () => BsApInvoice::create([
        'tenant_id' => $tenantId, 'invoice_number' => 'INV-UJI-'.Str::random(6), 'invoice_date' => now()->toDateString(),
        'supplier_id' => $sup, 'expense_account_id' => $acc, 'payable_account_id' => $acc, 'description' => 'Invoice uji',
        'dpp' => 5000000, 'total' => 5000000, 'net_payable' => 5000000, 'paid_amount' => 0, 'status' => 'open',
    ]);
    $sebagai($ap);
    $inv = $buatInvoice();
    $ia = $svc->ajukanInvoice($b->fresh(), $inv);
    $cek('berkas → diinvoice & tertaut', $b->fresh()->status === 'diinvoice' && (int) $b->fresh()->ap_invoice_id === (int) $inv->id);
    $cek('invoice bersumber berkas', $inv->fresh()->source_type === 'berkas' && (int) $inv->fresh()->source_id === (int) $b->id);
    $cek('approval invoice 5 tahap', $muat($ia->id)->steps->count() === 5);
    $cek('berkas yang sudah diinvoice tidak bisa diinvoice lagi', $kode(fn () => $svc->ajukanInvoice($b->fresh(), $buatInvoice())) === 'BERKAS_NOT_READY');

    $st = implode(',', array_column($inbox->stages($muat($ia->id)), 'label'));
    $cek('tracker invoice = alur penuh', $st === 'Pengunggah,Dept. Head (TTD),Tim AP,Accounting,Finance,GM,Direktur,MW / BA', $st);

    echo "\n=== 4. Gerbang bayar ===\n";
    $cek('menunggu approval → assertBolehDibayar menolak', $kode(fn () => $inv->fresh()->assertBolehDibayar()) === 'AP_MENUNGGU_APPROVAL');
    $cek('bayar langsung ditolak', $kode(fn () => app(PayApInvoice::class)->execute($inv->fresh(), ['payment_date' => now()->toDateString(), 'cash_account_id' => $acc, 'amount' => 1000])) === 'AP_MENUNGGU_APPROVAL');
    $cek('kuitansi ditolak', $kode(fn () => app(ApKuitansiService::class)->create([
        'supplier_id' => $sup, 'kuitansi_date' => now()->toDateString(), 'cash_account_id' => $acc,
        'lines' => [['ap_invoice_id' => $inv->id, 'amount' => 1000]],
    ], $ap->id)) === 'AP_MENUNGGU_APPROVAL');
    $lama = $buatInvoice();
    $cek('invoice lama (tanpa approval) tetap boleh dibayar', $kode(fn () => $lama->assertBolehDibayar()) === null);

    foreach (['Accounting', 'Finance', 'GM', 'Direktur', 'Owner'] as $r) {
        $sebagai($role[$r]);
        $act->execute($muat($ia->id), 'approve');
    }
    $cek('approval invoice approved', $muat($ia->id)->status === 'approved');
    $cek('setelah disetujui penuh → boleh dibayar', $kode(fn () => $inv->fresh()->assertBolehDibayar()) === null);

    echo "\n=== 5. Ditolak Dept Head ===\n";
    $sebagai($dh);
    $act->execute($muat($b2->approval_id), 'reject', 'Bukan tagihan dept saya');
    $cek('berkas ditolak', $b2->fresh()->status === 'ditolak');
    $cek('berkas ditolak tidak bisa diinvoice', $kode(fn () => $svc->assertSiapInvoice($b2->fresh())) === 'BERKAS_NOT_READY');

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

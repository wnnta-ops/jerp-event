<?php

declare(strict_types=1);

/*
 * Uji Registrasi Invoice Hutang › Detil Tagihan: pilih berkas dari Upload Berkas.
 *
 * Yang dijaga:
 *   · Pilihan berkas = HANYA unggahan user itu sendiri yang masih aktif (draft, ditolak,
 *     menunggu TTD, siap invoice, sebagian) — berkas user lain / dibatalkan tidak tampil.
 *   · Invoice baru tanpa berkas ditolak; berkas milik orang lain ditolak; berkas yang belum
 *     di-TTD Dept Head ditolak.
 *   · "Edit" berkas: isian wizard tersimpan sebagai draft (satu per user), lalu diarahkan ke
 *     Upload Berkas ?lanjut_edit=… (bukan langsung ke edit — supaya Back kembali ke Upload
 *     Berkas). Berkas yang sudah diajukan / milik orang lain tidak bisa di-edit lewat sini.
 *   · Draft bisa dibuang.
 *
 * Disk 'local' dipalsukan dan data DB DIBATALKAN (rollback).
 *
 * Jalankan:  php tests/Manual/uji-invoice-pilih-berkas.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\Approval\Actions\ActOnApproval;
use App\Domain\Approval\Models\Approval;
use App\Domain\BusinessSupport\Models\BsApInvoiceDraft;
use App\Domain\BusinessSupport\Services\ApBerkasService;
use App\Http\Controllers\Web\BusinessSupport\Payable\ApBerkasController;
use App\Http\Controllers\Web\BusinessSupport\Payable\ApInvoiceController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
    $karyawan = function (User $u, ?User $atasan = null) use ($tenantId): void {
        $nik = 'UJI-'.Str::upper(Str::random(8));
        DB::table('bs_employees')->insert([
            'tenant_id' => $tenantId, 'nik' => $nik, 'name' => $u->name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            'atasan_id' => $atasan ? DB::table('bs_employees')->where('nik', $atasan->employee_nip)->value('id') : null,
        ]);
        $u->forceFill(['employee_nip' => $nik])->save();
    };
    // SuperAdmin: punya ap.invoice.create & ap.berkas.upload, tanpa perlu menebak peran lain.
    $staf = $buatUser('Staf Invoice Uji', ['SuperAdmin']);
    $lain = $buatUser('Staf Lain Uji', ['SuperAdmin']);
    $dh = $buatUser('Dept Head Invoice Uji', ['DeptHead']);
    $karyawan($dh);
    $karyawan($staf, $dh);
    $karyawan($lain, $dh);

    $svc = app(ApBerkasService::class);
    $sebagai = function (User $u) { auth()->setUser($u); };
    $wajib = DB::table('bs_ap_berkas')->where('is_active', true)->where('is_mandatory', true)->pluck('id');
    $file = fn () => $wajib->map(fn ($id) => ['file' => UploadedFile::fake()->create("berkas-{$id}.pdf", 120, 'application/pdf'), 'berkas_id' => (int) $id])->all();
    $data = fn () => ['upload_date' => now()->toDateString(), 'description' => 'Tagihan uji pilih', 'document_date' => '2026-04-10', 'amount' => 2000000];

    $sebagai($staf);
    $draft = $svc->create($data(), $file(), $staf);                 // draft
    $menunggu = $svc->upload($data(), $file(), $staf);              // menunggu_ttd
    $siap = $svc->upload($data(), $file(), $staf);                  // → siap_invoice
    $batal = $svc->create($data(), $file(), $staf);
    $svc->cancel($batal, $staf, 'uji');                             // dibatalkan
    $sebagai($dh);
    app(ActOnApproval::class)->execute(Approval::with('steps')->findOrFail($siap->fresh()->approval_id), 'approve');
    $sebagai($lain);
    $milikLain = $svc->upload($data(), $file(), $lain);

    echo "\n=== 1. Pilihan berkas = milik sendiri yang aktif ===\n";
    $ids = array_column($svc->pilihanInvoice($staf), 'id');
    $cek('draft, menunggu TTD, siap invoice tampil', ! array_diff([$draft->id, $menunggu->id, $siap->id], $ids));
    $cek('berkas dibatalkan tidak tampil', ! in_array($batal->id, $ids, true));
    $cek('berkas user lain tidak tampil', ! in_array($milikLain->id, $ids, true));
    $p = collect($svc->pilihanInvoice($staf))->keyBy('id');
    $cek('siap invoice → bisa_diinvoice, tidak bisa diubah', $p[$siap->id]['bisa_diinvoice'] && ! $p[$siap->id]['bisa_diubah']);
    $cek('draft → bisa diubah, belum bisa diinvoice', $p[$draft->id]['bisa_diubah'] && ! $p[$draft->id]['bisa_diinvoice']);
    $cek('file ikut terkirim dengan URL', count($p[$siap->id]['files']) === $wajib->count() && str_contains($p[$siap->id]['files'][0]['url'], '/file/'));

    // Panggilan controller dengan sesi & referer, supaya back()->with() bisa dibaca.
    $ctlInv = app(ApInvoiceController::class);
    $ctlBerkas = app(ApBerkasController::class);
    $minta = function (string $method, array $isi = []) {
        $r = Request::create('/uji', $method, $isi, [], [], ['HTTP_REFERER' => url('/business-support/payable/create')]);
        $r->setLaravelSession(app('session.store'));
        $r->setUserResolver(fn () => auth()->user());
        app()->instance('request', $r);

        return $r;
    };
    $galatSesi = function () {
        $e = app('session.store')->get('error');
        app('session.store')->forget('error');

        return (string) $e;
    };

    echo "\n=== 2. Simpan invoice: berkas wajib, milik sendiri, sudah TTD ===\n";
    $sebagai($staf);
    $galat = null;
    try {
        app()->call([$ctlInv, 'store'], ['request' => $minta('POST', ['supplier_id' => 1])]);
    } catch (ValidationException $e) {
        $galat = $e->errors();
    }
    $cek('tanpa berkas → galat berkas_upload_id', isset($galat['berkas_upload_id']), json_encode($galat));
    app()->call([$ctlInv, 'store'], ['request' => $minta('POST', ['berkas_upload_id' => $milikLain->id])]);
    $cek('berkas milik orang lain ditolak', str_contains($galatSesi(), 'bukan unggahan Anda'));
    app()->call([$ctlInv, 'store'], ['request' => $minta('POST', ['berkas_upload_id' => $draft->id])]);
    $cek('berkas belum TTD ditolak', str_contains($galatSesi(), 'belum siap'));

    echo "\n=== 3. Edit berkas → draft invoice ===\n";
    $isian = ['supplier_id' => 7, 'description' => 'Invoice setengah jadi', 'berkas_upload_id' => $draft->id, 'lines' => [['description' => 'x', 'qty' => 1, 'amount' => 10]]];
    $res = $ctlInv->simpanDraft($minta('POST', ['data' => $isian, 'step' => 3, 'edit_berkas' => $draft->id]));
    $d = BsApInvoiceDraft::query()->where('user_id', $staf->id)->first();
    $cek('draft tersimpan (isian + langkah + berkas)', $d && $d->step === 3 && $d->data['description'] === 'Invoice setengah jadi' && (int) $d->berkas_upload_id === $draft->id);
    $cek('diarahkan ke Upload Berkas ?lanjut_edit=', str_contains($res->getTargetUrl(), '/business-support/payable-berkas') && str_contains($res->getTargetUrl(), 'lanjut_edit='.$draft->id), $res->getTargetUrl());
    $ctlInv->simpanDraft($minta('POST', ['data' => $isian + ['term_days' => 30], 'step' => 4]));
    $cek('simpan lagi → tetap satu draft per user, diperbarui', BsApInvoiceDraft::query()->where('user_id', $staf->id)->count() === 1
        && BsApInvoiceDraft::query()->where('user_id', $staf->id)->first()->step === 4);
    $res = $ctlInv->simpanDraft($minta('POST', ['data' => $isian, 'step' => 3, 'edit_berkas' => $siap->id]));
    $cek('berkas sudah di-TTD tidak bisa di-edit lewat sini', str_contains($galatSesi(), 'tidak bisa diedit') && ! str_contains($res->getTargetUrl(), 'lanjut_edit'));
    $ctlInv->simpanDraft($minta('POST', ['data' => $isian, 'step' => 3, 'edit_berkas' => $milikLain->id]));
    $cek('berkas orang lain tidak bisa di-edit lewat sini', str_contains($galatSesi(), 'tidak bisa diedit'));

    echo "\n=== 4. Upload Berkas meneruskan ke edit; banner draft ===\n";
    $lanjut = new ReflectionMethod($ctlBerkas, 'lanjutEdit');
    $cek('lanjut_edit berkas sendiri (draft) → diteruskan', $lanjut->invoke($ctlBerkas, $minta('GET', ['lanjut_edit' => $draft->id]), $staf) === $draft->id);
    $cek('lanjut_edit berkas orang lain → diabaikan', $lanjut->invoke($ctlBerkas, $minta('GET', ['lanjut_edit' => $milikLain->id]), $staf) === null);
    $adaDraft = new ReflectionMethod($ctlBerkas, 'adaDraftInvoice');
    $cek('banner draft tampil di berkas yang dipakai draft', $adaDraft->invoke($ctlBerkas, $staf, $draft) === true);
    $cek('banner draft tidak tampil di berkas lain', $adaDraft->invoke($ctlBerkas, $staf, $siap) === false);
    $cek('banner draft tidak tampil untuk user lain', $adaDraft->invoke($ctlBerkas, $lain, $draft) === false);

    echo "\n=== 5. Buang draft ===\n";
    $ctlInv->buangDraft($minta('DELETE'));
    $cek('draft terhapus', ! BsApInvoiceDraft::query()->where('user_id', $staf->id)->exists());

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

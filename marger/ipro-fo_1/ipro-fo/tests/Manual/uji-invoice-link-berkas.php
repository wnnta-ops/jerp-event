<?php

/**
 * Uji berkas di Detil Tagihan Registrasi Invoice Hutang: "Upload File" & "Generate Link" supplier.
 *  1. Upload File → Upload Berkas milik pembuat (draft — TTD Dept Head diajukan di langkah Struktur).
 *  2. Generate Link → nomor invoice dipesan, draft wizard tersimpan di link, halaman publik tampil.
 *  3. Supplier unggah (tanpa login): dokumen wajib dicek, jadi berkas milik pembuat (draft),
 *     link terisi, notifikasi ke pembuat, titik di daftar invoice; link tidak menerima kiriman kedua.
 *  4. Setelah TTD Dept Head, invoice disimpan memakai nomor yang dipesan; link selesai.
 *
 * Data DB DIBATALKAN (rollback). File uji ditulis ke disk lokal lalu dihapus.
 *
 * Jalankan:  php tests/Manual/uji-invoice-link-berkas.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\Approval\Actions\ActOnApproval;
use App\Domain\Approval\Models\Approval;
use App\Domain\BusinessSupport\Models\BsApBerkasLink;
use App\Domain\BusinessSupport\Models\BsApBerkasUpload;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Services\LinkBerkasSupplier;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Web\BusinessSupport\Payable\ApInvoiceController;
use App\Http\Controllers\Web\Public\BerkasSupplierController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
$pathsUji = [];

DB::beginTransaction();

try {
    $staf = User::where('email', 'staff@jerp.test')->firstOrFail();         // atasan (Dept Head) = pengaju@
    $deptHead = User::where('email', 'pengaju@jerp.test')->firstOrFail();
    auth()->setUser($staf);
    $minta = function (string $method, array $isi = [], array $files = []) {
        $r = Request::create('/uji', $method, $isi, [], $files, ['HTTP_REFERER' => url('/business-support/payable/create')]);
        $r->setLaravelSession(app('session.store'));
        $r->setUserResolver(fn () => auth()->user());
        app()->instance('request', $r);

        return $r;
    };
    $pdf = fn (string $nama) => UploadedFile::fake()->create($nama, 20, 'application/pdf');
    $jenis = DB::table('bs_ap_berkas')->where('is_active', 1)->pluck('id', 'code');
    $supplier = (int) DB::table('bs_suppliers')->where('is_active', 1)->whereNull('deleted_at')->whereNull('category')->orderBy('id')->value('id');
    $pakai = DB::table('bs_ap_category_usages')->where('jenis', 'invoice')->first();
    $tgl = now()->toDateString();
    $wizard = ['supplier_id' => $supplier, 'invoice_date' => $tgl, 'category_id' => $pakai->category_id, 'supplier_invoice_no' => 'SUP-LINK-1',
        'description' => 'Uji link berkas supplier', 'dpp' => 750_000, 'term_days' => 7, 'ppn_mode' => 'none', 'pph_mode' => 'none'];
    $svc = app(LinkBerkasSupplier::class);

    echo "=== 1. Upload File ===\n";
    $b = $svc->upload($staf, $wizard, [['file' => $pdf('inv.pdf'), 'berkas_id' => $jenis['INVOICE']], ['file' => $pdf('kui.pdf'), 'berkas_id' => $jenis['KUITANSI']]]);
    $pathsUji = array_merge($pathsUji, $b->files()->pluck('path')->all());
    $cek('jadi Upload Berkas milik pembuat (draft, belum diajukan)', (int) $b->uploaded_by === $staf->id && $b->status === BsApBerkasUpload::STATUS_DRAFT && (int) $b->dept_head_id === $deptHead->id, $b->status);
    $cek('isian berkas dari wizard (supplier, no. tagihan, nilai)', (int) $b->supplier_id === $supplier && $b->supplier_invoice_no === 'SUP-LINK-1' && (float) $b->amount === 750000.0);

    // Drop box Upload File (layar invoice): jenis tetap; "Lainnya" wajib keterangan.
    $ctlUp = app(App\Http\Controllers\Web\BusinessSupport\Payable\ApInvoiceBerkasController::class);
    $kirimUp = fn (array $files, array $berkas) => app()->call([$ctlUp, 'upload'], ['request' => $minta('POST',
        ['data' => json_encode($wizard), 'files' => $files], ['files' => $berkas])]);
    $tolakUp = null;
    try {
        $kirimUp([['berkas_id' => $jenis['INVOICE']], ['berkas_id' => $jenis['SURAT-JALAN']]], [['file' => $pdf('a.pdf')], ['file' => $pdf('b.pdf')]]);
    } catch (Illuminate\Validation\ValidationException $e) {
        $tolakUp = $e->errors();
    }
    $cek('Upload File: jenis di luar 4 jenis tetap → ditolak', isset($tolakUp['files.1.berkas_id']), json_encode($tolakUp));
    $tolakUp = null;
    try {
        $kirimUp([['berkas_id' => $jenis['INVOICE']], ['berkas_id' => $jenis['KUITANSI']], ['berkas_id' => $jenis['LAINNYA'], 'keterangan' => ' ']], [['file' => $pdf('a.pdf')], ['file' => $pdf('b.pdf')], ['file' => $pdf('c.pdf')]]);
    } catch (Illuminate\Validation\ValidationException $e) {
        $tolakUp = $e->errors();
    }
    $cek('Upload File: "Lainnya" tanpa keterangan → ditolak', isset($tolakUp['files.2.keterangan']), json_encode($tolakUp));
    $resUp = $kirimUp([['berkas_id' => $jenis['INVOICE']], ['berkas_id' => $jenis['KUITANSI']], ['berkas_id' => $jenis['LAINNYA'], 'keterangan' => 'Berita Acara']], [['file' => $pdf('a.pdf')], ['file' => $pdf('b.pdf')], ['file' => $pdf('c.pdf')]]);
    $bUp = BsApBerkasUpload::find($resUp->getData(true)['id'] ?? 0);
    $pathsUji = array_merge($pathsUji, $bUp?->files()->pluck('path')->all() ?? []);
    $cek('Upload File: "Lainnya" tersimpan dengan keterangan', $bUp && $bUp->files()->where('berkas_id', $jenis['LAINNYA'])->first()?->labelJenis() === 'Lainnya — Berita Acara');

    echo "\n=== 2. Generate Link ===\n";
    $cek('tanpa supplier → ditolak', $kode(fn () => $svc->buat($staf, ['invoice_date' => $tgl], 3)) === 'LINK_DATA');
    $link = $svc->buat($staf, $wizard + ['link_id' => 99], 3);
    $cek('nomor invoice dipesan (AP.INV/…), token 48 karakter, status menunggu', str_starts_with($link->invoice_number, 'AP.INV/') && strlen($link->token) === 48 && $link->status === 'menunggu', $link->invoice_number);
    $cek('draft wizard tersimpan di link', ($link->data['supplier_invoice_no'] ?? null) === 'SUP-LINK-1' && ! isset($link->data['link_id']));
    $cek('nomor belum aktif — tidak ada invoice dengan nomor itu', ! BsApInvoice::where('invoice_number', $link->invoice_number)->exists());
    $link2 = $svc->buat($staf, $wizard, 3);
    $cek('boleh banyak link sekaligus, nomor berbeda', $link2->invoice_number !== $link->invoice_number && $svc->aktifUntuk($staf)->count() >= 2);
    auth()->logout();
    $html = app()->call([app(BerkasSupplierController::class), 'show'], ['token' => $link->token])->getContent();
    $cek('halaman publik (tanpa login): isian No. Invoice kosong, nomor internal & "No. Tagihan Anda" tidak tampil, dokumen wajib ada',
        str_contains($html, 'name="no_invoice"') && ! str_contains($html, $link->invoice_number) && ! str_contains($html, 'No. Tagihan Anda') && str_contains($html, 'Invoice / Nota Supplier'));

    echo "\n=== 3. Supplier mengunggah ===\n";
    $cek('dokumen wajib kurang → ditolak', $kode(fn () => $svc->terimaDariSupplier($link, [['file' => $pdf('a.pdf'), 'berkas_id' => $jenis['INVOICE']]])) === 'LINK_WAJIB');
    $notifSebelum = DB::table('notifications')->where('notifiable_id', $staf->id)->where('type', 'berkas.supplier_terisi')->count();
    $cek('halaman supplier: hanya Invoice, Kuitansi, Faktur Pajak, Lainnya', str_contains($html, 'Faktur Pajak') && str_contains($html, 'Lainnya') && ! str_contains($html, 'Surat Jalan'));
    $tolakLain = null;
    try {
        app()->call([app(BerkasSupplierController::class), 'store'], ['request' => $minta('POST', [
            'no_invoice' => 'INV-SUP-001', 'files' => [['berkas_id' => $jenis['INVOICE']], ['berkas_id' => $jenis['KUITANSI']], ['berkas_id' => $jenis['LAINNYA'], 'keterangan' => '']],
        ], ['files' => [['file' => $pdf('i.pdf')], ['file' => $pdf('k.pdf')], ['file' => $pdf('l.pdf')]]]), 'token' => $link->token]);
    } catch (Illuminate\Validation\ValidationException $e) {
        $tolakLain = $e->errors();
    }
    $cek('"Lainnya" tanpa keterangan → ditolak', isset($tolakLain['files.2.keterangan']), json_encode($tolakLain));
    $tolakNo = null;
    try {
        app()->call([app(BerkasSupplierController::class), 'store'], ['request' => $minta('POST', [
            'files' => [['berkas_id' => $jenis['INVOICE']], ['berkas_id' => $jenis['KUITANSI']]],
        ], ['files' => [['file' => $pdf('i.pdf')], ['file' => $pdf('k.pdf')]]]), 'token' => $link->token]);
    } catch (Illuminate\Validation\ValidationException $e) {
        $tolakNo = $e->errors();
    }
    $cek('tanpa No. Invoice → ditolak', isset($tolakNo['no_invoice']), json_encode($tolakNo));
    app()->call([app(BerkasSupplierController::class), 'store'], ['request' => $minta('POST', [
        'no_invoice' => 'INV-SUP-777', 'files' => [['berkas_id' => $jenis['INVOICE']], ['berkas_id' => $jenis['KUITANSI']], ['berkas_id' => $jenis['LAINNYA'], 'keterangan' => 'Surat Kuasa']], 'catatan' => 'Mohon diproses',
    ], ['files' => [['file' => $pdf('inv-sup.pdf')], ['file' => $pdf('kui-sup.pdf')], ['file' => $pdf('kuasa.pdf')]]]), 'token' => $link->token]);
    $link->refresh();
    $bs = BsApBerkasUpload::find($link->berkas_upload_id);
    $pathsUji = array_merge($pathsUji, $bs?->files()->pluck('path')->all() ?? []);
    $cek('link terisi + berkas tercatat', $link->status === 'terisi' && $bs !== null, app('session.store')->get('error') ?? '');
    $cek('berkas milik pembuat invoice (draft, belum diajukan)', $bs && (int) $bs->uploaded_by === $staf->id && $bs->status === BsApBerkasUpload::STATUS_DRAFT && $bs->files()->count() === 3);
    $cek('file "Lainnya" tersimpan dengan keterangannya', $bs && $bs->files()->where('berkas_id', $jenis['LAINNYA'])->first()?->labelJenis() === 'Lainnya — Surat Kuasa');
    $cek('catatan supplier ikut di keterangan berkas', $bs && str_contains($bs->description, 'Mohon diproses'));
    $cek('No. Invoice dari supplier → berkas & draft link', $bs && $bs->supplier_invoice_no === 'INV-SUP-777' && ($link->data['supplier_invoice_no'] ?? null) === 'INV-SUP-777');
    $svc->simpanDraft($link, ['supplier_invoice_no' => 'DIUBAH-WIZARD'] + ($link->data ?? []), 2);
    $cek('Simpan Draft wizard tidak menimpa nomor dari supplier', ($link->fresh()->data['supplier_invoice_no'] ?? null) === 'INV-SUP-777');
    $notif = DB::table('notifications')->where('notifiable_id', $staf->id)->where('type', 'berkas.supplier_terisi')->get();
    $cek('pembuat dapat notifikasi "Supplier sudah mengisi … di <no invoice>"',
        $notif->count() === $notifSebelum + 1 && $notif->contains(fn ($n) => str_contains(json_decode($n->data, true)['body'] ?? '', $link->invoice_number)),
        $notif->count().' '.$notif->pluck('data')->implode(' | '));
    // Link tetap hidup: supplier boleh mengunggah ULANG — berkas baru menggantikan yang lama, riwayat tercatat.
    $bsLama = $bs;
    $bs = $svc->terimaDariSupplier($link->fresh(), [['file' => $pdf('inv2.pdf'), 'berkas_id' => $jenis['INVOICE']], ['file' => $pdf('kui2.pdf'), 'berkas_id' => $jenis['KUITANSI']]], null, 'INV-SUP-778');
    $pathsUji = array_merge($pathsUji, $bs->files()->pluck('path')->all());
    $link->refresh();
    $cek('unggah ulang lewat link yang sama → diterima, berkas baru jadi berkas link', (int) $link->berkas_upload_id === $bs->id && $bs->id !== $bsLama->id && $link->status === 'terisi');
    $cek('berkas lama dibatalkan (keluar dari TTD Dept Head)', $bsLama->fresh()->status === BsApBerkasUpload::STATUS_DIBATALKAN);
    $cek('riwayat unggahan tercatat 2× (waktu, berkas, no. invoice)', count($link->riwayat ?? []) === 2 && $link->riwayat[1]['no_invoice'] === 'INV-SUP-778' && $link->riwayat[1]['berkas_number'] === $bs->berkas_number);
    $cek('no. invoice ikut unggahan terbaru', ($link->data['supplier_invoice_no'] ?? null) === 'INV-SUP-778');
    $html2 = app()->call([app(BerkasSupplierController::class), 'show'], ['token' => $link->token])->getContent();
    $cek('halaman link tetap menerima + menampilkan riwayat', str_contains($html2, 'Kirim Ulang Dokumen') && str_contains($html2, 'Riwayat unggahan') && str_contains($html2, 'INV-SUP-778'));

    auth()->setUser($staf);
    if (! $staf->can('ap.invoice.view')) {   // titik tampil di daftar invoice — butuh akses lihat daftar
        $staf->givePermissionTo('ap.invoice.view');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $staf = $staf->fresh();
        auth()->setUser($staf);
    }
    $ctl = app(ApInvoiceController::class);
    $props = (fn () => $this->props)->call(app()->call([$ctl, 'indexJenis'], ['request' => $minta('GET'), 'jenis' => 'invoice']));
    $baris = collect($props['menungguSupplier'])->firstWhere('id', $link->id);
    $cek('daftar invoice: titik di sebelah nomor (berkas masuk, belum dibuka)', $baris && $baris['baru'] && $baris['invoice_number'] === $link->invoice_number);
    $props = (fn () => $this->props)->call(app()->call([$ctl, 'create'], ['request' => $minta('GET', ['link' => $link->id])]));
    $cek('buka dari link → draft dipulihkan + berkas terpilih', ($props['draftInvoice']['data']['link_id'] ?? null) === $link->id
        && (int) ($props['draftInvoice']['data']['berkas_upload_id'] ?? 0) === $bs->id && ($props['linkBerkas']['status'] ?? null) === 'terisi');
    $cek('setelah dibuka, titiknya hilang', $link->fresh()->seen_at !== null);

    echo "\n=== 4. Simpan invoice dengan nomor yang dipesan ===\n";
    // Langkah Struktur: "Ajukan ke Dept Head" — isian tersimpan di link, berkas diajukan ke TTD.
    auth()->setUser($staf);
    app(\App\Domain\BusinessSupport\Services\PengajuanDeptHead::class)->ajukan($staf, $wizard + ['berkas_upload_id' => $bs->id], 4, null, $link->id);
    $cek('Ajukan ke Dept Head (link) → berkas menunggu TTD, isian di link', $bs->fresh()->status === BsApBerkasUpload::STATUS_MENUNGGU_TTD && (int) $link->fresh()->step === 4);
    $cek('pindah ke panel "Menunggu TTD Dept Head"', collect(app(\App\Domain\BusinessSupport\Services\PengajuanDeptHead::class)->menunggu($staf))->contains(fn ($m) => $m['berkas_id'] === $bs->id && str_contains($m['url'], 'link='.$link->id)));
    auth()->setUser($deptHead);
    app(ActOnApproval::class)->execute(Approval::with('steps')->findOrFail($bs->fresh()->approval_id), 'approve');
    auth()->setUser($staf);
    $akunDebit = (int) DB::table('bs_accounts')->where('type', 'asset')->where('is_postable', 1)->where('is_active', 1)->where('cost_center_optional', 1)->orderBy('code')->value('id');
    $isi = $wizard + ['berkas_upload_id' => $bs->id, 'link_id' => $link->id, 'receive_date' => $tgl,
        'lines' => [['description' => 'Jasa uji', 'qty' => 1, 'amount' => 750_000]],
        'journal_lines' => [
            ['account_id' => $akunDebit, 'cost_center_id' => null, 'debit' => 750_000, 'credit' => 0, 'description' => 'uji'],
            ['account_id' => (int) $pakai->payable_account_id, 'cost_center_id' => null, 'debit' => 0, 'credit' => 750_000, 'description' => 'hutang'],
        ]];
    app()->call([$ctl, 'store'], ['request' => $minta('POST', ['supplier_invoice_no' => 'KETIK-LAIN'] + $isi)]);
    $inv = BsApInvoice::where('invoice_number', $link->invoice_number)->first();
    $cek('No. Invoice Supplier invoice = nomor yang diisi supplier di link (unggahan terbaru)', $inv?->supplier_invoice_no === 'INV-SUP-778', (string) $inv?->supplier_invoice_no);
    $cek('setelah invoice disimpan, link ditutup', $kode(fn () => $svc->terimaDariSupplier($link->fresh(), [['file' => $pdf('c.pdf'), 'berkas_id' => $jenis['INVOICE']], ['file' => $pdf('d.pdf'), 'berkas_id' => $jenis['KUITANSI']]], null, 'X')) === 'LINK_TUTUP');
    $galat = app('session.store')->get('error') ?? json_encode(app('session.store')->get('errors')?->getBag('default')->toArray());
    $cek('invoice tersimpan memakai nomor yang dipesan link', $inv !== null, (string) $galat);
    $cek('link selesai (dipakai) & keluar dari daftar menunggu', $link->fresh()->status === 'dipakai' && (int) $link->fresh()->ap_invoice_id === $inv?->id
        && ! $svc->aktifUntuk($staf)->contains('id', $link->id));

    echo "\n".str_repeat('─', 78)."\n";
    echo $gagal === 0 ? "SEMUA LULUS ({$lulus})\n" : "GAGAL: {$gagal}, lulus: {$lulus}\n";
} catch (Throwable $e) {
    echo "\nGALAT: ".$e->getMessage()."\n  di ".$e->getFile().':'.$e->getLine()."\n";
    $gagal++;
} finally {
    DB::rollBack();
    Storage::disk('local')->delete($pathsUji);
    echo "(data uji dibatalkan)\n";
}

exit($gagal === 0 ? 0 : 1);

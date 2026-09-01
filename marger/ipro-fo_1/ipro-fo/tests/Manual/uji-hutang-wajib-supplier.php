<?php

declare(strict_types=1);

/*
 * Uji "tidak ada hutang tanpa supplier".
 *
 * Baris "—" pada Aging Hutang menggabungkan hutang milik SIAPA PUN yang supplier-nya hilang
 * jadi satu angka gelondongan: tidak bisa ditagih, tidak bisa ditelusuri, dan tidak ada yang
 * tahu itu milik siapa. Tiga hal bisa menyebabkannya, dan ketiganya harus tertutup:
 *
 *   1. supplier_id NULL — dicegah NOT NULL di basis data.
 *   2. supplier_id menunjuk baris yang sudah lenyap — dicegah foreign key.
 *   3. supplier ber-soft-delete membuat relasinya memulangkan null — ditutup withTrashed()
 *      pada relasinya, DAN dengan menolak penghapusan supplier yang masih punya hutang.
 *
 * Yang ketiga inilah yang paling mudah terjadi tanpa disadari: menghapus supplier tidak
 * menghapus invoicenya, dan layarnya tidak memberi tanda apa pun.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan:  docker exec ipro-fo-app php tests/Manual/uji-hutang-wajib-supplier.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Http\Controllers\Web\BusinessSupport\Payable\ApInvoiceController;
use App\Http\Controllers\Web\BusinessSupport\Setup\SupplierController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

DB::beginTransaction();

try {
    $userId = (int) DB::table('users')->orderBy('id')->value('id');
    $user   = \App\Models\User::query()->find($userId);
    $akun   = (int) DB::table('bs_accounts')->where('is_postable', true)->orderBy('id')->value('id');

    echo "\n=== 1. Basis data menolak hutang tanpa supplier ===\n";

    // Ini penjaga yang tidak bisa dilangkahi kode mana pun — termasuk import, seeder, dan
    // perbaikan manual lewat SQL.
    $kolom = DB::selectOne("SELECT IS_NULLABLE n FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name='bs_ap_invoices' AND column_name='supplier_id'");
    $cek('kolom supplier_id tidak boleh kosong', ($kolom->n ?? '') === 'NO', (string) ($kolom->n ?? '?'));

    $fk = DB::selectOne("SELECT r.DELETE_RULE d FROM information_schema.KEY_COLUMN_USAGE k
        JOIN information_schema.REFERENTIAL_CONSTRAINTS r
          ON r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA
        WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='bs_ap_invoices' AND k.COLUMN_NAME='supplier_id'");
    $cek('supplier_id ber-foreign key ke bs_suppliers', $fk !== null, 'tidak ada foreign key');
    $cek('penghapusan supplier ditolak foreign key (RESTRICT)', ($fk->d ?? '') === 'RESTRICT',
        (string) ($fk->d ?? '?'));

    // ── Fikstur ──────────────────────────────────────────────────────────────────────────────
    $sup = BsSupplier::create([
        'code' => 'UJI-SP-1', 'name' => 'UJI Supplier Berhutang', 'is_active' => true,
    ]);
    $inv = BsApInvoice::create([
        'invoice_number' => 'UJI-AP-1', 'invoice_date' => '2026-03-01', 'due_date' => '2026-03-31',
        'supplier_id' => $sup->id, 'payable_account_id' => $akun, 'expense_account_id' => $akun,
        'description' => 'UJI hutang', 'trx_type' => 'normal', 'status' => 'open',
        'total' => 10000000, 'net_payable' => 10000000, 'paid_amount' => 0, 'created_by' => $userId,
    ]);

    echo "\n=== 2. Menyimpan hutang tanpa supplier ditolak ===\n";

    $tertolak = false;
    try {
        DB::table('bs_ap_invoices')->insert([
            'tenant_id' => $tenantId, 'invoice_number' => 'UJI-AP-NULL', 'invoice_date' => '2026-03-01',
            'due_date' => '2026-03-31', 'supplier_id' => null, 'payable_account_id' => $akun,
            'expense_account_id' => $akun, 'description' => 'UJI tanpa supplier', 'trx_type' => 'normal',
            'status' => 'open', 'total' => 1000, 'paid_amount' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    } catch (\Throwable $e) {
        $tertolak = true;
    }
    $cek('insert dengan supplier_id NULL ditolak', $tertolak, 'baris tanpa supplier berhasil masuk');

    $tertolak2 = false;
    try {
        DB::table('bs_ap_invoices')->insert([
            'tenant_id' => $tenantId, 'invoice_number' => 'UJI-AP-HANTU', 'invoice_date' => '2026-03-01',
            'due_date' => '2026-03-31', 'supplier_id' => 999999999, 'payable_account_id' => $akun,
            'expense_account_id' => $akun, 'description' => 'UJI supplier hantu', 'trx_type' => 'normal',
            'status' => 'open', 'total' => 1000, 'paid_amount' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    } catch (\Throwable $e) {
        $tertolak2 = true;
    }
    $cek('insert ke supplier yang tidak ada ditolak', $tertolak2, 'baris menunjuk id hantu berhasil masuk');

    echo "\n=== 3. Supplier yang masih berhutang tidak bisa dihapus ===\n";

    $req = Request::create('/x', 'DELETE');
    $req->setUserResolver(fn () => $user);
    $resp = app(SupplierController::class)->destroy($req, $sup);
    $pesan = (string) ($resp->getSession()?->get('error') ?? '');

    $cek('penghapusan ditolak', $pesan !== '', 'tidak ada pesan penolakan');
    $cek('alasannya disebutkan berikut jumlah invoicenya',
        str_contains($pesan, 'tidak bisa dihapus') && str_contains($pesan, '1 invoice'), $pesan);
    $cek('disarankan menonaktifkan sebagai gantinya', str_contains($pesan, 'Nonaktifkan'), $pesan);
    $cek('suppliernya memang masih ada', BsSupplier::whereKey($sup->id)->exists());

    echo "\n=== 4. Supplier yang sudah terlanjur dihapus tetap bernama ===\n";

    // Data lama bisa saja sudah punya supplier ber-soft-delete sebelum larangan di atas ada.
    // Tanpa withTrashed() pada relasinya, hutangnya jatuh ke baris "—".
    DB::table('bs_suppliers')->where('id', $sup->id)->update(['deleted_at' => now()]);

    $segar = BsApInvoice::with('supplier')->find($inv->id);
    $cek('relasi supplier tetap memulangkan datanya', $segar?->supplier !== null,
        'relasi null — hutang ini akan jadi baris "—"');
    $cek('namanya terbaca', ($segar?->supplier?->name ?? '') === 'UJI Supplier Berhutang',
        (string) ($segar?->supplier?->name ?? 'null'));

    $ctl = app(ApInvoiceController::class);
    $m = new ReflectionMethod(ApInvoiceController::class, 'namaSupplier');
    $m->setAccessible(true);
    $nama = (string) $m->invoke($ctl, $segar);
    $cek('penamaannya menyebut nama, bukan "—"', $nama !== '—' && str_contains($nama, 'UJI Supplier Berhutang'), $nama);
    $cek('ditandai sebagai dihapus', str_contains($nama, '(dihapus)'), $nama);

    echo "\n=== 5. Aging Hutang tidak lagi punya baris tanpa nama ===\n";

    $reqA = Request::create('/x', 'GET', ['pull' => 1, 'date_to' => '2026-12-31', 'date_from' => '2026-01-01']);
    $reqA->setUserResolver(fn () => $user);
    $props = $ctl->aging($reqA)->toResponse($reqA)->getOriginalContent()->getData()['page']['props'];

    $rows = collect($props['rows']);
    $cek('ada baris hutang yang terbaca', $rows->isNotEmpty(), '0 baris');
    // Inilah permintaannya, diperiksa apa adanya atas seluruh data yang ada.
    $cek('TIDAK ADA baris supplier bernama "—"',
        $rows->doesntContain(fn ($r) => trim((string) $r['name']) === '—'),
        json_encode($rows->pluck('name')->all()));
    $cek('TIDAK ADA baris supplier tanpa nama',
        $rows->doesntContain(fn ($r) => trim((string) $r['name']) === ''),
        json_encode($rows->pluck('name')->all()));
    $cek('hutang uji ikut tampil atas nama suppliernya',
        $rows->contains(fn ($r) => str_contains((string) $r['name'], 'UJI Supplier Berhutang')),
        json_encode($rows->pluck('name')->all()));

    // Layar Aging TIDAK boleh jatuh hanya karena satu eager-load lupa menyebut deleted_at.
    // Aplikasi ini berjalan dengan mode atribut ketat: membaca kolom yang tidak ter-select
    // melempar exception, dan seluruh laporan hutang ikut mati — jauh lebih parah daripada
    // sekadar kehilangan penanda "(dihapus)".
    $invRingkas = BsApInvoice::with('supplier:id,code,name')->find($inv->id);
    $aman = true;
    try {
        $m->invoke($ctl, $invRingkas);
    } catch (Throwable $e) {
        $aman = false;
    }
    $cek('penamaan tetap aman walau deleted_at tidak ikut dimuat', $aman,
        'membaca deleted_at yang tak ter-select melempar exception dan menjatuhkan layar Aging');

    $rincian = collect($props['details']);
    $cek('rinciannya juga tidak ada yang bernama "—"',
        $rincian->doesntContain(fn ($r) => trim((string) $r['supplier']) === '—'),
        json_encode($rincian->pluck('supplier')->unique()->values()->all()));

    echo "\n=== 6. Migrasinya menyediakan penampung, bukan menghapus data ===\n";

    // Baris yang terlanjur yatim dipindahkan ke supplier penampung bernama jelas supaya tetap
    // terlihat dan bisa dibetulkan — bukan dibuang.
    $adaMigrasi = DB::table('migrations')->where('migration', '2026_08_29_100000_hutang_wajib_bersupplier')->exists();
    $cek('migrasinya sudah dijalankan', $adaMigrasi);
    $cek('tabel bs_ap_invoices masih utuh', Schema::hasTable('bs_ap_invoices'));

    echo "\n==================================================\n";
    echo "  LULUS {$lulus} · GAGAL {$gagal}\n";
    echo "==================================================\n";
} finally {
    DB::rollBack();
    $sisa = BsApInvoice::withTrashed()->where('invoice_number', 'like', 'UJI-AP-%')->count()
        + BsSupplier::withTrashed()->where('code', 'like', 'UJI-SP-%')->count();
    echo 'rollback selesai; sisa data uji: '.$sisa."\n";
}

exit($gagal > 0 ? 1 : 0);

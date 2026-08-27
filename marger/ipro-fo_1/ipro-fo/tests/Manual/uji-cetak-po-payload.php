<?php

declare(strict_types=1);

/**
 * Uji: data yang dikirim ke layar Cetak Purchase Order.
 *
 * Jalankan:  docker exec ipro-fo-app php artisan tinker /var/www/html/tests/Manual/uji-cetak-po-payload.php
 *
 * Melengkapi tests/Manual/uji-cetak-po-format.cjs yang menguji tata letaknya. Di sini yang
 * diperiksa asal datanya: blok penerimaan supplier di kaki dokumen, nama pembuat PO pada
 * kolom "Dibuat Oleh", dan batas mana saja yang boleh dibekukan lewat "Simpan Format".
 *
 * Semua yang menulis dibungkus transaksi lalu di-rollback.
 */

use App\Domain\BusinessSupport\Models\BsPurchaseOrder;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Http\Controllers\Web\BusinessSupport\Purchasing\PurchaseOrderController;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$cek = function (string $nama, bool $ok, string $ket = '') use (&$lulus, &$gagal): void {
    if ($ok) {
        $lulus++;
        echo "  OK    {$nama}\n";
    } else {
        $gagal++;
        echo "  GAGAL {$nama}".($ket !== '' ? " — {$ket}" : '')."\n";
    }
};

$user = User::query()->get()->first(fn ($u) => $u->canModule('bs'));
if (! $user) {
    echo "Tidak ada pengguna dengan akses modul bs.\n";

    return;
}
auth()->setUser($user);

$cetak = function (array $ids) use ($user): array {
    $req = Illuminate\Http\Request::create('/bs/po/print', 'GET', ['ids' => implode(',', $ids)]);
    $req->setUserResolver(fn () => $user);

    return app(PurchaseOrderController::class)->print($req)->toResponse($req)
        ->getOriginalContent()->getData()['page']['props'];
};

DB::beginTransaction();
try {
    $supplier = BsSupplier::query()->first();
    $cek('supplier tersedia', $supplier !== null);

    $po = BsPurchaseOrder::create([
        'supplier_id' => $supplier->id, 'po_number' => 'PO/UJI-CETAK/0001', 'po_date' => '2026-07-13',
        'po_type' => 'barang', 'subtotal' => 4_237_500, 'ppn_pct' => 11, 'ppn_amount' => 466_125,
        'total' => 4_703_625, 'status' => 'approved', 'term_payment' => 'Nett 30 hari',
        'created_by' => $user->id,
    ]);

    $form = $cetak([(int) $po->id])['orders'][0]['form'];

    echo "\n=== 1. Blok penerimaan supplier di kaki dokumen ===\n";

    // Blok ini ada pada lampiran acuan tetapi sebelumnya tidak pernah tercetak, sehingga
    // lembar yang dikirim ke supplier tidak punya tempat tanda tangannya dan pengembalian
    // PO bertanda tangan tidak bisa ditagih.
    $cek('label penerimaan terkirim', ($form['diterima_label'] ?? null) === 'Disetujui dan diterima',
        (string) ($form['diterima_label'] ?? '-'));
    $cek('nama supplier terisi otomatis', ($form['diterima_nama'] ?? null) === $supplier->name,
        (string) ($form['diterima_nama'] ?? '-'));

    echo "\n=== 2. \"Dibuat Oleh\" terisi pembuat PO-nya ===\n";

    // Pada lampiran acuan kolom ini bernama orang. Membiarkannya kosong memaksa operator
    // mengetik ulang namanya setiap kali mencetak.
    $cek('nama pembuat PO ikut terkirim', ($form['ttd'][0]['nama'] ?? null) === $user->name,
        (string) ($form['ttd'][0]['nama'] ?? '-'));
    $cek('labelnya tetap "Dibuat Oleh,"', ($form['ttd'][0]['label'] ?? null) === 'Dibuat Oleh,');
    $cek('tiga blok tanda tangan', count($form['ttd'] ?? []) === 3, (string) count($form['ttd'] ?? []));

    echo "\n=== 3. Angka & baris SELALU dari data PO, tidak bisa dibekukan ===\n";

    // "Simpan Format" hanya boleh membekukan field presentasi. Kalau angka ikut tersimpan,
    // PO yang direvisi akan tetap tercetak dengan total lama — cetakan dan catatan berbeda
    // tanpa ada yang menyadarinya.
    $po->update(['print_meta' => [
        'diterima_nama' => 'PT SUPPLIER DISUNTING',
        'nomor'         => 'PO/DIPALSUKAN/9999',
        'subtotal'      => 1,
        'total'         => 1,
    ]]);
    $form2 = $cetak([(int) $po->id])['orders'][0]['form'];

    $cek('field presentasi yang disimpan memang dipakai',
        $form2['diterima_nama'] === 'PT SUPPLIER DISUNTING', (string) $form2['diterima_nama']);
    $cek('nomor PO TIDAK bisa dibekukan', $form2['nomor'] === 'PO/UJI-CETAK/0001', (string) $form2['nomor']);
    $cek('subtotal TIDAK bisa dibekukan', (float) $form2['subtotal'] !== 1.0, (string) $form2['subtotal']);
    $cek('total TIDAK bisa dibekukan', (float) $form2['total'] !== 1.0, (string) $form2['total']);

    echo "\n=== 4. Seluruh kunci yang dibaca layar cetak lengkap ===\n";

    // Print.vue men-dereference tiap kunci tanpa penjagaan — satu kunci hilang menjatuhkan
    // seluruh halaman cetak, bukan satu baris.
    $wajib = ['nomor', 'sistem_pembayaran', 'kepada', 'kepada_alamat', 'proyek', 'kirim_ke',
        'kirim_alamat', 'tanggal_order', 'pelaksanaan', 'penerima', 'items', 'subtotal',
        'ppn_pct', 'ppn_amount', 'total', 'catatan', 'cara_pembayaran', 'garansi',
        'kelengkapan', 'pengambilan', 'pembayaran', 'ttd', 'diterima_label', 'diterima_nama'];
    $kurang = array_values(array_diff($wajib, array_keys($form2)));
    $cek('24 kunci form lengkap', $kurang === [], implode(',', $kurang));
} finally {
    DB::rollBack();
}

echo "\n=== 5. Rollback bersih ===\n";
$cek('PO uji tidak tertinggal', BsPurchaseOrder::where('po_number', 'like', 'PO/UJI-CETAK/%')->count() === 0);

echo "\n==================================================\n";
echo "  LULUS {$lulus} · GAGAL {$gagal}\n";
echo "==================================================\n";

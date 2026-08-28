<?php

declare(strict_types=1);

/**
 * Uji: kolom Invoice Hutang pada daftar Purchase Order.
 *
 * Jalankan:  docker exec ipro-fo-app php artisan tinker /var/www/html/tests/Manual/uji-po-invoice-hutang.php
 *
 * Kolom ini menggantikan "Keterangan" yang dulu menampilkan teks bebas bs_purchase_orders.notes.
 * Isinya kini nomor dokumen Invoice Hutang beserta keterangannya, diambil dari tautan
 * bs_ap_invoices.source_type='po' + source_id.
 *
 * Yang paling menentukan: source_type WAJIB ikut disaring. Pasangannya generik — source_id
 * yang sama dipakai juga oleh SPK, refund, dan batch — sehingga mencocokkan source_id saja
 * akan menempelkan dokumen milik jenis sumber lain ke PO yang kebetulan berid sama, dan
 * operator akan membaca PO itu sebagai sudah ditagih padahal belum.
 *
 * Semua yang menulis dibungkus transaksi lalu di-rollback.
 */

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApInvoice;
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

/** Panggil daftar PO apa adanya dan pulangkan props-nya. */
$layar = function () use ($user): array {
    $req = Illuminate\Http\Request::create('/bs/po', 'GET');
    $req->setUserResolver(fn () => $user);

    return app(PurchaseOrderController::class)->index($req)->toResponse($req)
        ->getOriginalContent()->getData()['page']['props'];
};

/** Baris PO tertentu dari props. */
$baris = function (array $props, int $poId): ?array {
    foreach ($props['orders'] as $o) {
        if ((int) $o['id'] === $poId) {
            return $o;
        }
    }

    return null;
};

DB::beginTransaction();
try {
    $supplier = BsSupplier::query()->first();
    $akun = BsAccount::where('is_postable', true)->orderBy('code')->limit(2)->get();
    $cek('supplier & dua akun tersedia', $supplier !== null && $akun->count() === 2);
    if (! $supplier || $akun->count() < 2) {
        throw new RuntimeException('data pendukung kurang');
    }

    $buatPo = fn (string $no) => BsPurchaseOrder::create([
        'supplier_id' => $supplier->id, 'po_number' => $no, 'po_date' => '2026-08-18',
        'po_type' => 'barang', 'subtotal' => 1_000_000, 'total' => 1_000_000,
        'status' => 'closed', 'notes' => 'CATATAN PO '.$no,
    ]);

    $nomorUrut = 0;
    $buatInvoice = function (?string $sourceType, ?int $sourceId, string $desc, string $tgl)
    use ($supplier, $akun, &$nomorUrut) {
        $nomorUrut++;

        return BsApInvoice::create([
            'supplier_id' => $supplier->id,
            'invoice_number' => 'AP.INV/UJI-PO/MUP/26/VIII/'.str_pad((string) $nomorUrut, 5, '0', STR_PAD_LEFT),
            'invoice_date' => $tgl, 'description' => $desc,
            'expense_account_id' => $akun[0]->id, 'payable_account_id' => $akun[1]->id,
            'dpp' => 1_000_000, 'total' => 1_000_000, 'net_payable' => 1_000_000,
            'status' => 'open', 'trx_type' => 'normal',
            'source_type' => $sourceType, 'source_id' => $sourceId,
        ]);
    };

    echo "\n=== 1. PO tanpa invoice ===\n";

    $poKosong = $buatPo('PO/UJI/0001');
    $r = $baris($layar(), (int) $poKosong->id);
    $cek('barisnya ada di daftar', $r !== null);
    $cek('ap_invoices kosong', ($r['ap_invoices'] ?? null) === []);
    $cek('notes tetap dikirim (dipakai tooltip & kolom Catatan PO di Excel)',
        ($r['notes'] ?? null) === 'CATATAN PO PO/UJI/0001', (string) ($r['notes'] ?? '-'));

    echo "\n=== 2. PO dengan satu invoice ===\n";

    $poSatu = $buatPo('PO/UJI/0002');
    $inv = $buatInvoice('po', (int) $poSatu->id, 'Beli Pulsa', '2026-08-20');
    $r = $baris($layar(), (int) $poSatu->id);
    $cek('tepat satu invoice', count($r['ap_invoices']) === 1, (string) count($r['ap_invoices']));
    $cek('nomor dokumennya benar', $r['ap_invoices'][0]['number'] === $inv->invoice_number,
        (string) $r['ap_invoices'][0]['number']);
    $cek('keterangannya ikut terbawa', $r['ap_invoices'][0]['description'] === 'Beli Pulsa',
        (string) $r['ap_invoices'][0]['description']);

    echo "\n=== 3. Jenis sumber lain TIDAK ikut tertarik ===\n";

    // Inilah kekeliruan yang paling mudah terjadi dan paling sulit terlihat: source_id sama,
    // jenis sumbernya berbeda. Tanpa saringan source_type, PO ini akan tampak sudah ditagih.
    $poBersih = $buatPo('PO/UJI/0003');
    $buatInvoice('spk', (int) $poBersih->id, 'Tagihan SPK, bukan PO', '2026-08-21');
    $buatInvoice(null, (int) $poBersih->id, 'Tanpa jenis sumber', '2026-08-22');
    $r = $baris($layar(), (int) $poBersih->id);
    $cek('invoice ber-source_type SPK tidak ikut', ($r['ap_invoices'] ?? null) === [],
        json_encode($r['ap_invoices'] ?? null));

    echo "\n=== 4. Invoice terhapus hilang, invoice lunas TETAP tampil ===\n";

    $poHapus = $buatPo('PO/UJI/0004');
    $invHapus = $buatInvoice('po', (int) $poHapus->id, 'Akan dihapus', '2026-08-20');
    $cek('sebelum dihapus, invoicenya terlihat',
        count($baris($layar(), (int) $poHapus->id)['ap_invoices']) === 1);
    $invHapus->delete();
    $cek('sesudah dihapus, kolomnya kembali kosong',
        $baris($layar(), (int) $poHapus->id)['ap_invoices'] === []);

    $poLunas = $buatPo('PO/UJI/0005');
    $buatInvoice('po', (int) $poLunas->id, 'Sudah lunas', '2026-08-20')->update(['status' => 'paid']);
    // Menyaring status adalah kesalahan yang menggoda: 'paid' justru keadaan sehat, dan
    // menyaringnya akan mengosongkan kolom pada PO yang sudah selesai dibayar.
    $cek('invoice berstatus lunas tetap ditampilkan',
        count($baris($layar(), (int) $poLunas->id)['ap_invoices']) === 1);

    echo "\n=== 5. Satu PO dengan dua invoice, urut tanggal ===\n";

    $poDua = $buatPo('PO/UJI/0006');
    // Yang dibuat BELAKANGAN diberi tanggal LEBIH AWAL — supaya urutannya terbukti mengikuti
    // tanggal invoice, bukan sekadar urutan penyimpanan.
    $invB = $buatInvoice('po', (int) $poDua->id, 'Termin dua', '2026-08-25');
    $invA = $buatInvoice('po', (int) $poDua->id, 'Termin satu', '2026-08-10');
    $r = $baris($layar(), (int) $poDua->id);
    $cek('dua invoice terbawa', count($r['ap_invoices']) === 2, (string) count($r['ap_invoices']));
    $cek('urut menaik menurut tanggal invoice',
        $r['ap_invoices'][0]['number'] === $invA->invoice_number
        && $r['ap_invoices'][1]['number'] === $invB->invoice_number,
        $r['ap_invoices'][0]['number'].' lalu '.$r['ap_invoices'][1]['number']);

    echo "\n=== 6. Bukan N+1 ===\n";

    // Daftar PO mengirim SELURUH baris tanpa paginasi, jadi query per-baris akan tumbuh
    // sebanding jumlah PO dan halaman ini yang paling cepat melambat.
    $hitung = function () use ($layar): int {
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        $layar();

        return $n;
    };
    $sedikit = $hitung();
    for ($i = 7; $i <= 16; $i++) {
        $po = $buatPo('PO/UJI/'.str_pad((string) $i, 4, '0', STR_PAD_LEFT));
        $buatInvoice('po', (int) $po->id, 'Tagihan '.$i, '2026-08-20');
        $buatInvoice('po', (int) $po->id, 'Tagihan lanjutan '.$i, '2026-08-26');
    }
    $banyak = $hitung();
    $cek('menambah 10 PO + 20 invoice tidak menambah jumlah query',
        $banyak === $sedikit, $sedikit.' → '.$banyak);

    echo "\n=== 7. Bentuk data yang dibaca layar ===\n";

    $r = $baris($layar(), (int) $poSatu->id);
    $inv0 = $r['ap_invoices'][0];
    $cek('tiap invoice punya kunci id, number, description',
        array_keys($inv0) === ['id', 'number', 'description'], implode(',', array_keys($inv0)));
    $cek('number bertipe string', is_string($inv0['number']));
    $cek('id bertipe integer', is_int($inv0['id']));

    // Rangkaian yang sama dengan invText() di Index.vue — inilah yang dilihat operator.
    $tampil = $inv0['number'].($inv0['description'] ? ' — '.$inv0['description'] : '');
    $cek('terbaca "NOMOR — Keterangan"', $tampil === $inv->invoice_number.' — Beli Pulsa', $tampil);
    echo "        contoh tampilan: {$tampil}\n";
} finally {
    DB::rollBack();
}

echo "\n=== 8. Rollback bersih ===\n";
$cek('PO uji tidak tertinggal',
    BsPurchaseOrder::where('po_number', 'like', 'PO/UJI/%')->count() === 0);
$cek('invoice uji tidak tertinggal',
    BsApInvoice::withTrashed()->where('invoice_number', 'like', 'AP.INV/UJI-PO/%')->count() === 0);

echo "\n==================================================\n";
echo "  LULUS {$lulus} · GAGAL {$gagal}\n";
echo "==================================================\n";

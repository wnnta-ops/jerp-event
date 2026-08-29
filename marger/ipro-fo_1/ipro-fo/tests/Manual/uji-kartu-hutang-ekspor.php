<?php

declare(strict_types=1);

/*
 * Uji ekspor Excel & PDF pada Kartu Hutang.
 *
 * YANG DIJAGA: isi berkasnya harus SAMA dengan yang tampil di layar.
 *
 * Ini bukan kekhawatiran teoretis. Kartu Hutang memakai basis NET (setelah PPN & PPh) dengan
 * cutoff s/d tanggal acuan, dan angkanya harus cocok dengan Aging Hutang. Bila ekspornya
 * menghitung sendiri, salinannya akan menyimpang perlahan setiap kali salah satunya
 * disesuaikan — dan berkas yang berbeda dari layarnya TIDAK menimbulkan galat apa pun.
 * Keduanya sama-sama masuk akal dilihat sendiri-sendiri; selisihnya baru ketahuan kalau ada
 * yang menjumlahkan ulang dengan tangan.
 *
 * Nilai dibaca dari SEL-nya, bukan dicari sebagai substring di seluruh berkas. Angka
 * berformat saling bersarang — "5.000.000" ada di dalam "15.000.000" — sehingga pencarian
 * substring bisa hijau atas angka milik baris lain, dan tidak bisa membedakan "barisnya
 * hilang" dari "nilainya salah".
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan:  docker exec ipro-fo-app php tests/Manual/uji-kartu-hutang-ekspor.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsApPayment;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Http\Controllers\Web\BusinessSupport\Payable\ApInvoiceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

/** Isi seluruh sel pada baris berlabel tertentu; null bila barisnya tidak ada. */
function selBaris(string $html, string $label): ?array
{
    foreach (preg_split('/<tr\b/i', $html) ?: [] as $baris) {
        if (! str_contains($baris, '>'.$label.'<')) {
            continue;
        }
        preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $baris, $sel);

        return array_map(fn ($v) => trim(strip_tags((string) $v)), $sel[1] ?? []);
    }

    return null;
}

/** Semua baris (array sel) dari sebuah tabel HTML. @return array<int,array<int,string>> */
function semuaBaris(string $html): array
{
    $out = [];
    foreach (preg_split('/<tr\b/i', $html) ?: [] as $baris) {
        preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $baris, $sel);
        if (($sel[1] ?? []) !== []) {
            $out[] = array_map(fn ($v) => trim(strip_tags((string) $v)), $sel[1]);
        }
    }

    return $out;
}

DB::beginTransaction();

try {
    $userId = (int) DB::table('users')->orderBy('id')->value('id');
    $user   = \App\Models\User::query()->find($userId);
    $akun   = (int) DB::table('bs_accounts')->where('is_postable', true)->orderBy('id')->value('id');

    $cek('user uji berhak membuka Kartu Hutang', (bool) $user?->canModule('ap'), 'user '.$userId);

    // ── Fikstur: satu supplier, tiga invoice, satu pembayaran ────────────────────────────────
    $sup = BsSupplier::create(['code' => 'UJI-KH-1', 'name' => 'UJI Supplier Kartu', 'is_active' => true]);

    // Invoice SEBELUM periode → membentuk Saldo Awal.
    BsApInvoice::create([
        'invoice_number' => 'UJI-KH-INV-0', 'invoice_date' => '2026-01-10', 'due_date' => '2026-02-10',
        'supplier_id' => $sup->id, 'payable_account_id' => $akun, 'expense_account_id' => $akun,
        'description' => 'UJI tagihan sebelum periode', 'trx_type' => 'normal', 'status' => 'open',
        'total' => 5000000, 'net_payable' => 5000000, 'paid_amount' => 0, 'created_by' => $userId,
    ]);
    $inv1 = BsApInvoice::create([
        'invoice_number' => 'UJI-KH-INV-1', 'invoice_date' => '2026-03-05', 'due_date' => '2026-04-05',
        'supplier_id' => $sup->id, 'payable_account_id' => $akun, 'expense_account_id' => $akun,
        'description' => 'UJI tagihan pertama', 'trx_type' => 'normal', 'status' => 'open',
        'total' => 10000000, 'net_payable' => 10000000, 'paid_amount' => 0, 'created_by' => $userId,
    ]);
    BsApInvoice::create([
        'invoice_number' => 'UJI-KH-INV-2', 'invoice_date' => '2026-03-20', 'due_date' => '2026-04-20',
        'supplier_id' => $sup->id, 'payable_account_id' => $akun, 'expense_account_id' => $akun,
        'description' => 'UJI tagihan kedua', 'trx_type' => 'normal', 'status' => 'open',
        'total' => 2500000, 'net_payable' => 2500000, 'paid_amount' => 0, 'created_by' => $userId,
    ]);
    BsApPayment::create([
        'ap_invoice_id' => $inv1->id, 'payment_date' => '2026-03-15', 'amount' => 3000000,
        'cash_account_id' => $akun, 'reference' => 'UJI-BAYAR-1', 'created_by' => $userId,
    ]);

    $ctl = app(ApInvoiceController::class);
    $par = ['supplier_id' => $sup->id, 'date_from' => '2026-03-01', 'date_to' => '2026-03-31'];

    $mkReq = function (array $q) use ($user) {
        $r = Request::create('/x', 'GET', $q);
        $r->setUserResolver(fn () => $user);

        return $r;
    };

    $layar = $ctl->card($mkReq($par))->toResponse($mkReq($par))->getOriginalContent()->getData()['page']['props'];
    $xls   = $ctl->cardExportExcel($mkReq($par))->getContent();
    $pdf   = $ctl->cardExportPdf($mkReq($par))->getContent();

    $rp = fn ($n) => number_format((float) $n, 0, ',', '.');

    echo "\n=== 1. Layar menghitung sesuai basis NET & cutoff ===\n";

    $cek('saldo awal dari tagihan sebelum periode', (float) $layar['openingBalance'] === 5000000.0,
        (string) $layar['openingBalance']);
    $cek('tiga baris mutasi dalam periode', count($layar['entries']) === 3, (string) count($layar['entries']));
    // 5jt awal + 10jt − 3jt + 2,5jt = 14,5jt
    $cek('saldo akhir 14.500.000', (float) $layar['outstanding'] === 14500000.0, (string) $layar['outstanding']);

    // ── Pemeriksaan yang dipakai untuk Excel & PDF sekaligus ─────────────────────────────────
    $periksaBerkas = function (string $nama, string $html) use ($cek, $layar, $rp): void {
        echo "\n=== Berkas {$nama} berisi angka yang SAMA dengan layar ===\n";

        $cek("[{$nama}] judul memuat supplier", str_contains($html, 'UJI Supplier Kartu'), 'tidak ditemukan');

        $awal = selBaris($html, 'Saldo Awal');
        $cek("[{$nama}] baris Saldo Awal ada", $awal !== null, 'baris tidak ditemukan');
        // Sel TERAKHIR baris itu = kolom Saldo. Dibaca, bukan dicari.
        $cek("[{$nama}] nilai Saldo Awal sama dengan layar",
            $awal !== null && end($awal) === $rp($layar['openingBalance']),
            $awal === null ? 'baris hilang' : 'berkas="'.end($awal).'" layar="'.$rp($layar['openingBalance']).'"');

        $tot = selBaris($html, 'SALDO HUTANG');
        $cek("[{$nama}] baris SALDO HUTANG ada", $tot !== null, 'baris tidak ditemukan');
        $cek("[{$nama}] nilai Saldo Akhir sama dengan layar",
            $tot !== null && end($tot) === $rp($layar['outstanding']),
            $tot === null ? 'baris hilang' : 'berkas="'.end($tot).'" layar="'.$rp($layar['outstanding']).'"');

        // Tiap baris mutasi harus ada, dengan saldo berjalan yang sama persis.
        $baris = semuaBaris($html);
        foreach ($layar['entries'] as $e) {
            $cocok = null;
            foreach ($baris as $b) {
                if (in_array((string) $e['ref'], $b, true) && in_array($rp($e['balance']), $b, true)) {
                    $cocok = $b;
                    break;
                }
            }
            $cek("[{$nama}] baris {$e['ref']} ada dengan saldo ".$rp($e['balance']), $cocok !== null,
                'tidak ada baris yang memuat keduanya');
        }
    };

    $periksaBerkas('Excel', $xls);
    $periksaBerkas('PDF', $pdf);

    echo "\n=== 2. Ciri khas tiap berkas ===\n";

    $cek('Excel: periode tercetak', str_contains($xls, '2026-03-01') && str_contains($xls, '2026-03-31'), 'tidak ditemukan');
    $cek('Excel: pembayaran ikut tercetak', str_contains($xls, 'UJI-BAYAR-1'), 'tidak ditemukan');
    // Tanpa ini Excel membaca "UJI-KH-INV-1" sebagai teks biasa tetapi memangkas nol di depan
    // pada kode lain, dan nomor bertanggal berubah jadi tanggal.
    $cek('Excel: kolom identitas dipaksa jadi teks', str_contains($xls, "mso-number-format:'\\@'"), 'tidak ditemukan');
    $cek('Excel: bertipe berkas Excel',
        str_contains($ctl->cardExportExcel($mkReq($par))->headers->get('Content-Type') ?? '', 'ms-excel'),
        (string) $ctl->cardExportExcel($mkReq($par))->headers->get('Content-Type'));
    $cek('PDF: siap dicetak sendiri', str_contains($pdf, 'window.print()'), 'tidak ada pemicu cetak');

    echo "\n=== 3. Saringan ikut terbawa ke berkasnya ===\n";

    // Ekspor yang mengabaikan filter akan memulangkan SELURUH data — terlihat wajar, tetapi
    // bukan yang sedang dilihat operator.
    $sempit = ['supplier_id' => $sup->id, 'date_from' => '2026-03-18', 'date_to' => '2026-03-31'];
    $xls2 = $ctl->cardExportExcel($mkReq($sempit))->getContent();
    $cek('tagihan di luar periode tidak ikut', ! str_contains($xls2, 'UJI-KH-INV-1'),
        'UJI-KH-INV-1 (5 Mar) ikut terbawa padahal periode mulai 18 Mar');
    $cek('tagihan dalam periode tetap ikut', str_contains($xls2, 'UJI-KH-INV-2'), 'tidak ditemukan');

    // Supplier lain tidak boleh bocor.
    $sup2 = BsSupplier::create(['code' => 'UJI-KH-2', 'name' => 'UJI Supplier Lain', 'is_active' => true]);
    BsApInvoice::create([
        'invoice_number' => 'UJI-KH-LAIN', 'invoice_date' => '2026-03-10', 'due_date' => '2026-04-10',
        'supplier_id' => $sup2->id, 'payable_account_id' => $akun, 'expense_account_id' => $akun,
        'description' => 'UJI supplier lain', 'trx_type' => 'normal', 'status' => 'open',
        'total' => 777000, 'net_payable' => 777000, 'paid_amount' => 0, 'created_by' => $userId,
    ]);
    $xls3 = $ctl->cardExportExcel($mkReq($par))->getContent();
    $cek('invoice supplier lain tidak bocor', ! str_contains($xls3, 'UJI-KH-LAIN'), 'UJI-KH-LAIN ikut terbawa');

    echo "\n=== 4. Mode Summary ikut terekspor ===\n";

    $ring = ['view' => 'summary', 'date_from' => '2026-03-01', 'date_to' => '2026-03-31'];
    $layarR = $ctl->card($mkReq($ring))->toResponse($mkReq($ring))->getOriginalContent()->getData()['page']['props'];
    $xlsR   = $ctl->cardExportExcel($mkReq($ring))->getContent();
    $pdfR   = $ctl->cardExportPdf($mkReq($ring))->getContent();

    $cek('layar summary punya isi', count($layarR['summary']) > 0, (string) count($layarR['summary']));
    $barisUji = collect($layarR['summary'])->firstWhere('name', 'UJI Supplier Kartu');
    $cek('supplier uji ada di rekap', $barisUji !== null);

    foreach ([['Excel', $xlsR], ['PDF', $pdfR]] as [$nm, $doc]) {
        $b = selBaris($doc, 'UJI Supplier Kartu');
        $cek("[{$nm}] baris rekap supplier uji ada", $b !== null, 'baris tidak ditemukan');
        $cek("[{$nm}] outstandingnya sama dengan layar",
            $b !== null && $barisUji !== null && in_array($rp($barisUji['outstanding']), $b, true),
            $b === null ? 'baris hilang' : json_encode($b).' vs '.$rp($barisUji['outstanding'] ?? 0));
    }
    $cek('Excel summary memakai kolom rekap, bukan kolom mutasi',
        str_contains($xlsR, 'Tertagih') && ! str_contains($xlsR, '<th>Saldo</th>'), 'kolomnya keliru');

    echo "\n=== 5. Izin tetap dijaga ===\n";

    $tanpaUser = Request::create('/x', 'GET', $par);
    $tanpaUser->setUserResolver(fn () => null);
    $ditolak = false;
    try {
        $ctl->cardExportExcel($tanpaUser);
    } catch (\Throwable $e) {
        $ditolak = true;
    }
    $cek('ekspor tanpa izin ditolak', $ditolak, 'berkas terunduh tanpa pemeriksaan izin');

    echo "\n==================================================\n";
    echo "  LULUS {$lulus} · GAGAL {$gagal}\n";
    echo "==================================================\n";
} finally {
    DB::rollBack();
    $sisa = BsApInvoice::withTrashed()->where('invoice_number', 'like', 'UJI-KH-%')->count()
        + BsSupplier::withTrashed()->where('code', 'like', 'UJI-KH-%')->count();
    echo 'rollback selesai; sisa data uji: '.$sisa."\n";
}

exit($gagal > 0 ? 1 : 0);

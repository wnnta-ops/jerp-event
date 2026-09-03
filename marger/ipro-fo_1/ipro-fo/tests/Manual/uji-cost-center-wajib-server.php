<?php

declare(strict_types=1);

/*
 * Uji rambu SERVER: cost center wajib pada Registrasi Invoice Hutang.
 *
 * Penjagaan di layar (tombol Berikutnya tertahan) hanya berlaku bagi yang memakai wizardnya
 * apa adanya. Kiriman langsung, tab yang tertinggal terbuka sejak sebelum aturan ini ada, atau
 * draf yang dipulihkan peramban tetap lolos — dan baris jurnal tanpa cost center tidak
 * terhitung di Budget Control maupun laporan per cost center. Itu tidak muncul sebagai galat
 * apa pun; hanya angka yang kurang, berbulan kemudian.
 *
 * PENGECUALIANNYA ikut diuji: 71 dari 267 akun postable bertanda cost_center_optional
 * (CoaTanpaCostCenterSeeder) — Utang Usaha Supplier dan sebagian akun neraca lain. Rambu yang
 * mewajibkan SEMUA baris akan menolak invoice yang justru paling biasa: Dr beban / Cr Utang
 * Usaha. Yang wajib ditahan, yang dikecualikan harus lewat.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan:  docker exec ipro-fo-app php tests/Manual/uji-cost-center-wajib-server.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Http\Controllers\Web\BusinessSupport\Payable\ApInvoiceController;
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

$ctrl = new ApInvoiceController();
$kurang = function (array $lines) use ($ctrl): array {
    $m = new ReflectionMethod($ctrl, 'akunKurangCostCenter');
    $m->setAccessible(true);

    return $m->invoke($ctrl, $lines);
};

DB::beginTransaction();

try {
    $wajib1 = BsAccount::where('code', '50302020213')->first(['id', 'code', 'name', 'cost_center_optional']);   // JASA OUTSOURCE
    $wajib2 = BsAccount::where('code', '10105010101')->first(['id', 'code', 'name', 'cost_center_optional']);   // PPN MASUKAN
    $bebas  = BsAccount::where('code', '20101010101')->first(['id', 'code', 'name', 'cost_center_optional']);   // UTANG USAHA SUPPLIER
    foreach (['50302020213' => $wajib1, '10105010101' => $wajib2, '20101010101' => $bebas] as $kode => $a) {
        if (! $a) {
            throw new RuntimeException("akun {$kode} tidak ada di Bagan Akun");
        }
    }
    $cc = (int) DB::table('bs_cost_centers')->orderBy('id')->value('id');
    if (! $cc) {
        throw new RuntimeException('tidak ada cost center untuk dijadikan fikstur');
    }

    echo "\n=== 1. Penandanya sesuai data sebenarnya ===\n";

    // Kalau penandanya berbeda dari yang diasumsikan, seluruh uji di bawah menguji hal lain.
    $cek('JASA OUTSOURCE wajib cost center', ! (bool) $wajib1->cost_center_optional);
    $cek('PPN MASUKAN wajib cost center', ! (bool) $wajib2->cost_center_optional);
    $cek('UTANG USAHA SUPPLIER dikecualikan', (bool) $bebas->cost_center_optional);

    $jml = BsAccount::where('is_postable', true)->where('cost_center_optional', true)->count();
    $cek('ada akun yang dikecualikan di master ('.$jml.' akun)', $jml > 0, (string) $jml);

    echo "\n=== 2. Baris wajib tanpa cost center DITOLAK ===\n";

    $hasil = $kurang([
        ['account_id' => $wajib1->id, 'cost_center_id' => null, 'debit' => 1000, 'credit' => 0],
        ['account_id' => $bebas->id,  'cost_center_id' => null, 'debit' => 0, 'credit' => 1000],
    ]);
    $cek('tepat satu akun dilaporkan kurang', count($hasil) === 1, json_encode($hasil));
    $cek('yang dilaporkan akun yang wajib', str_contains($hasil[0] ?? '', '50302020213'), json_encode($hasil));
    // Pesannya harus bisa ditelusuri ke barisnya, bukan sekadar "cost center wajib diisi".
    $cek('laporannya memuat nama akunnya', str_contains($hasil[0] ?? '', 'JASA OUTSOURCE'), json_encode($hasil));

    echo "\n=== 3. Yang dikecualikan LEWAT ===\n";

    // Ini bentuk invoice paling biasa: Dr beban (ber-cost center) / Cr Utang Usaha (tanpa).
    // Rambu yang menolaknya membuat modulnya tidak bisa dipakai sama sekali.
    $cek('Dr beban ber-CC / Cr Utang Usaha tanpa CC diterima',
        $kurang([
            ['account_id' => $wajib1->id, 'cost_center_id' => $cc, 'debit' => 1000, 'credit' => 0],
            ['account_id' => $bebas->id,  'cost_center_id' => null, 'debit' => 0, 'credit' => 1000],
        ]) === [], 'masih ada yang dilaporkan');

    $cek('seluruh baris ber-cost center diterima',
        $kurang([
            ['account_id' => $wajib1->id, 'cost_center_id' => $cc, 'debit' => 1000, 'credit' => 0],
            ['account_id' => $wajib2->id, 'cost_center_id' => $cc, 'debit' => 0, 'credit' => 1000],
        ]) === []);

    echo "\n=== 4. Baris tanpa akun & jurnal kosong tidak ditahan ===\n";

    // Layar Struktur Jurnal membolehkan baris kosong sebagai pengisi; barisnya dibuang saat
    // diproses. Menahannya berarti orang harus menghapus baris kosong satu per satu.
    $cek('baris tanpa akun diabaikan',
        $kurang([['account_id' => null, 'cost_center_id' => null, 'debit' => 0, 'credit' => 0]]) === []);
    $cek('tanpa baris jurnal sama sekali tidak ditahan', $kurang([]) === []);

    echo "\n=== 5. Dua akun kurang → keduanya disebut ===\n";

    // Menyebut satu saja membuat orang membetulkan satu, mengirim ulang, lalu ditolak lagi.
    $dua = $kurang([
        ['account_id' => $wajib1->id, 'cost_center_id' => null, 'debit' => 1000, 'credit' => 0],
        ['account_id' => $wajib2->id, 'cost_center_id' => null, 'debit' => 0, 'credit' => 1000],
    ]);
    $cek('keduanya dilaporkan', count($dua) === 2, json_encode($dua));
    $cek('urut menurut kode akunnya', $dua === array_values(collect($dua)->sort()->all()), json_encode($dua));

    echo "\n=== 6. Akun yang sama pada dua baris hanya disebut sekali ===\n";

    // Jurnal berisi sepuluh baris akun yang sama tidak boleh melahirkan sepuluh pesan.
    $ulang = $kurang([
        ['account_id' => $wajib1->id, 'cost_center_id' => null, 'debit' => 500, 'credit' => 0],
        ['account_id' => $wajib1->id, 'cost_center_id' => null, 'debit' => 500, 'credit' => 0],
    ]);
    $cek('disebut sekali saja', count($ulang) === 1, json_encode($ulang));

    // Baris yang SUDAH ber-cost center tidak boleh membuat akunnya lolos untuk baris lain
    // yang masih kosong — pemeriksaannya per baris, bukan per akun.
    $campur = $kurang([
        ['account_id' => $wajib1->id, 'cost_center_id' => $cc, 'debit' => 500, 'credit' => 0],
        ['account_id' => $wajib1->id, 'cost_center_id' => null, 'debit' => 500, 'credit' => 0],
    ]);
    $cek('satu baris terisi tidak menutupi baris lain yang kosong', count($campur) === 1, json_encode($campur));

    echo "\n=== 7. store() BENAR-BENAR menolaknya, bukan cuma punya aturannya ===\n";

    /* Pemeriksaan di atas memanggil helpernya langsung. Itu membuktikan aturannya benar, TETAPI
       bukan bahwa aturannya terpasang: melepas pemanggilannya di store() tetap lolos seluruh
       bagian 1-6. Aturan yang ada tapi tidak terpasang persis sama tak bergunanya dengan tidak
       ada. Jadi di sini store() dijalankan sungguhan. */
    $supplierId = (int) DB::table('bs_suppliers')->where('is_active', true)->orderBy('id')->value('id');
    $kategoriId = (int) DB::table('bs_ap_categories')->orderBy('id')->value('id');
    if (! $supplierId || ! $kategoriId) {
        throw new RuntimeException('butuh minimal satu supplier aktif & satu kategori AP');
    }
    $user = \App\Models\User::query()->find((int) DB::table('users')->orderBy('id')->value('id'));
    auth()->setUser($user);

    $kirim = function (?int $ccBarisWajib) use ($ctrl, $user, $supplierId, $kategoriId, $wajib1, $bebas) {
        $req = \Illuminate\Http\Request::create('/x', 'POST', [
            'supplier_id' => $supplierId, 'category_id' => $kategoriId, 'trx_type' => 'normal',
            'supplier_invoice_no' => 'UJI-CC-'.bin2hex(random_bytes(3)),
            'invoice_date' => '2026-09-03', 'term_days' => 30,
            'description' => 'UJI cost center wajib', 'dpp' => 1000000,
            'ppn_mode' => 'none', 'pph_mode' => 'none',
            'journal_lines' => [
                ['account_id' => $wajib1->id, 'cost_center_id' => $ccBarisWajib, 'debit' => 1000000, 'credit' => 0, 'currency' => 'IDR', 'kurs' => 1],
                ['account_id' => $bebas->id,  'cost_center_id' => null, 'debit' => 0, 'credit' => 1000000, 'currency' => 'IDR', 'kurs' => 1],
            ],
        ]);
        $req->setUserResolver(fn () => $user);
        $req->setLaravelSession(app('session.store'));
        app('session.store')->flush();

        try {
            $res = $ctrl->store($req, app(\App\Domain\BusinessSupport\Actions\RegisterApInvoice::class),
                app(\App\Domain\BusinessSupport\Services\BudgetGuardService::class));
        } catch (\Throwable $e) {
            return ['galat' => $e->getMessage(), 'ledak' => true];
        }

        return ['galat' => app('session.store')->get('error'), 'ledak' => false];
    };

    $sebelum = DB::table('bs_ap_invoices')->count();
    $tolak = $kirim(null);
    $cek('kiriman tanpa cost center ditolak', ! empty($tolak['galat']), json_encode($tolak));
    $cek('pesannya menyebut cost center wajib',
        str_contains((string) $tolak['galat'], 'Cost center wajib diisi'), (string) $tolak['galat']);
    $cek('penolakannya rapi, bukan exception', $tolak['ledak'] === false, (string) $tolak['galat']);
    $cek('tidak ada invoice yang terlanjur tersimpan',
        DB::table('bs_ap_invoices')->count() === $sebelum,
        'bertambah '.(DB::table('bs_ap_invoices')->count() - $sebelum));

    // Kiriman yang SAH harus tetap lewat — rambu yang menolak semuanya juga "lulus" uji di atas.
    $terima = $kirim($cc);
    $cek('kiriman ber-cost center tidak tertahan rambu ini',
        ! str_contains((string) ($terima['galat'] ?? ''), 'Cost center wajib diisi'),
        (string) ($terima['galat'] ?? '(tanpa galat)'));

    echo "\n".str_repeat('─', 78)."\n";
    echo $gagal === 0 ? "SEMUA LULUS ({$lulus})\n" : "GAGAL: {$gagal}, lulus: {$lulus}\n";
} catch (\Throwable $e) {
    echo "\nGALAT: ".$e->getMessage()."\n  di ".$e->getFile().':'.$e->getLine()."\n";
    $gagal++;
} finally {
    DB::rollBack();
    echo "(data uji dibatalkan)\n";
}

exit($gagal === 0 ? 0 : 1);

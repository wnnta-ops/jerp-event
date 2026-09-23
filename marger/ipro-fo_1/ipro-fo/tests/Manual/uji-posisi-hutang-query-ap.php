<?php

/**
 * Penyaring Hutang Usaha (AP) / Biaya yang Masih Harus Dibayar (BYMHD) / Tampilkan Semua
 * pada KEDELAPAN layar Query di modul Account Payable.
 *
 * SIFAT YANG DIJAGA DI ATAS SEGALANYA — dan yang membuat penyaring ini bisa dipercaya:
 *
 *      Tampilkan Semua  ===  Hutang Usaha  +  BYMHD
 *
 * Bukan kira-kira, tetapi sama persis, di setiap layar dan setiap berkas ekspornya. Sifat itu
 * tidak dijaga dengan kehati-hatian melainkan secara aljabar: PosisiHutang menulis SATU
 * predikat ("invoice ini BYMHD") dan memakai NEGASINYA untuk Hutang Usaha, sehingga mustahil
 * ada baris yang meleset dari kedua pilihan sekaligus. Uji ini membuktikannya dengan data yang
 * sengaja memuat setiap jalur klasifikasi yang mungkin:
 *
 *   A. invoice dengan kategori berposisi 'hutang'
 *   B. invoice dengan kategori berposisi 'beban_ymhd'
 *   C. invoice TANPA kategori, akun kreditnya Hutang Usaha  → harus jatuh ke Hutang Usaha
 *   D. invoice TANPA kategori, akun kreditnya Beban YMHD    → harus jatuh ke BYMHD
 *   E. invoice yang category_id-nya menunjuk kategori TERHAPUS → tetap harus mendarat di salah satu
 *
 * Kasus C & D bukan kasus pinggiran: bs_ap_invoices.category_id KOSONG pada seluruh invoice
 * Saldo Awal dan seluruh invoice hasil Posting — hanya Registrasi Invoice Hutang manual yang
 * mengisinya. Kalau invoice tanpa kategori dibuang dari kedua pilihan, hutang nyata akan
 * menghilang dari Aging, Kartu Hutang, dan Proyeksi begitu operator memilih sebuah posisi,
 * dan kehilangan itu hanya terlihat sebagai angka yang lebih kecil.
 *
 * KENAPA KUNCINYA KATEGORI, BUKAN AKUN PADA HEADER INVOICE: seluruh kategori yang ada hari ini
 * menunjuk akun yang SAMA, dan untuk posisi 'hutang' akunnya bebas dipilih operator. Penyaring
 * berbasis akun akan memulangkan "BYMHD selalu kosong" — tampak bekerja, tanpa galat, dan tidak
 * berarti apa-apa. Akun hanya dipakai sebagai CADANGAN untuk invoice yang tidak punya kategori.
 *
 * Yang juga dijaga:
 *  - EKSPOR SAMA DENGAN LAYAR. Tiap berkas ekspor dibangun query tersendiri; yang terlewat akan
 *    memuat seluruh posisi sementara layarnya tersaring, tanpa satu pun tanda.
 *  - ANGKA TOTAL DIHITUNG DARI BARIS YANG DITAMPILKAN. Kartu Pembayaran Hutang menghitung
 *    totalnya lewat query TERPISAH dari daftarnya; yang tidak ikut disaring membuat Total lebih
 *    besar daripada jumlah barisnya sendiri.
 *  - PENGHAPUSAN TANPA INVOICE TIDAK HILANG saat "Tampilkan Semua" (ap_invoice_id nullable).
 *  - PROYEKSI PIUTANG tidak ikut tersaring — di sana sumbernya invoice AR yang tidak punya
 *    kategori AP sama sekali.
 *  - REKAP PPh menyisihkan baris yang bukan invoice hutang (PPh 21 Payroll, jurnal manual) dan
 *    MELAPORKAN jumlahnya, bukan menghilangkannya diam-diam.
 *
 * Seluruh tulisan dibungkus transaksi lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-posisi-hutang-query-ap.php';"
 */

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApCategory;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Support\PosisiHutang as P;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$tuntas = false;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};

$user = \App\Models\User::all()->first(fn ($u) => $u->hasRole('SuperAdmin')) ?? \App\Models\User::first();
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
auth()->setUser($user);
app()->instance('tenant.id', (int) $user->tenant_id);
$tenant = (int) $user->tenant_id;

/** Props Inertia sungguhan dari sebuah controller — bukan tiruan. */
$props = function (callable $panggil, array $query = []) {
    $r = \Illuminate\Http\Request::create('/uji', 'GET', $query);
    $r->headers->set('X-Inertia', 'true');
    $r->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());
    $r->setUserResolver(fn () => auth()->user());

    return json_decode((string) $panggil($r)->toResponse($r)->getContent(), true)['props'] ?? [];
};
$isi = function (callable $panggil, array $query = []) {
    $r = \Illuminate\Http\Request::create('/uji', 'GET', $query);
    $r->setUserResolver(fn () => auth()->user());
    $resp = $panggil($r);
    if ($resp instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
        ob_start(); $resp->sendContent(); return (string) ob_get_clean();
    }

    return (string) $resp->getContent();
};

$TAG = 'ZPH'.strtoupper(substr((string) getmypid(), -4));

DB::beginTransaction();

try {
    $akunHutang = BsAccount::where('code', config('business_support.gl_accounts.payable'))->firstOrFail();
    $akunBeban  = BsAccount::where('code', config('business_support.gl_accounts.accrued_expense'))->firstOrFail();
    $akunBiaya  = BsAccount::where('code', '<>', $akunHutang->code)->firstOrFail();

    $supplierId = (int) DB::table('bs_suppliers')->insertGetId([
        'tenant_id' => $tenant, 'code' => 'SUP-'.$TAG, 'name' => 'Supplier Uji Posisi',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $katHutang = BsApCategory::create([
        'tenant_id' => $tenant, 'code' => 'KAT-H-'.$TAG, 'name' => 'Kategori Hutang Uji',
        'debit_account_id' => $akunBiaya->id, 'payable_account_id' => $akunHutang->id,
        'credit_position' => P::HUTANG, 'is_active' => true,
    ]);
    $katBeban = BsApCategory::create([
        'tenant_id' => $tenant, 'code' => 'KAT-B-'.$TAG, 'name' => 'Kategori Beban Uji',
        'debit_account_id' => $akunBiaya->id, 'payable_account_id' => $akunBeban->id,
        'credit_position' => P::BEBAN, 'is_active' => true,
    ]);
    $katHapus = BsApCategory::create([
        'tenant_id' => $tenant, 'code' => 'KAT-X-'.$TAG, 'name' => 'Kategori Terhapus Uji',
        'debit_account_id' => $akunBiaya->id, 'payable_account_id' => $akunHutang->id,
        'credit_position' => P::BEBAN, 'is_active' => true,
    ]);

    $buat = function (string $suffix, ?int $catId, int $akunKredit, float $nilai) use ($tenant, $supplierId, $akunBiaya, $TAG) {
        return BsApInvoice::create([
            'tenant_id' => $tenant, 'invoice_number' => 'INV-'.$TAG.'-'.$suffix,
            'invoice_date' => now()->subDays(10)->toDateString(),
            'due_date' => now()->addDays(20)->toDateString(),
            'supplier_id' => $supplierId, 'category_id' => $catId,
            'expense_account_id' => $akunBiaya->id, 'payable_account_id' => $akunKredit,
            'description' => 'Uji posisi '.$suffix, 'trx_type' => 'normal', 'status' => 'open',
            // payable_amount & outstanding adalah ACCESSOR, bukan kolom — diturunkan dari
            // net_payable dan paid_amount. Mengisinya di sini hanya akan ditolak mass assignment.
            'dpp' => $nilai, 'total' => $nilai, 'net_payable' => $nilai, 'paid_amount' => 0,
        ]);
    };

    $A = $buat('A', (int) $katHutang->id, (int) $akunHutang->id, 1_000_000);
    $B = $buat('B', (int) $katBeban->id,  (int) $akunBeban->id,  2_000_000);
    $C = $buat('C', null,                 (int) $akunHutang->id,   400_000);
    $D = $buat('D', null,                 (int) $akunBeban->id,    800_000);
    $E = $buat('E', (int) $katHapus->id,  (int) $akunHutang->id,   100_000);
    $F = $buat('F', (int) $katHapus->id,  (int) $akunBeban->id,    300_000);
    // Kategorinya dihapus SESUDAH invoice terbit — meniru kategori yang dipensiunkan operator.
    $katHapus->delete();

    $semuaNo = ['A' => $A->invoice_number, 'B' => $B->invoice_number, 'C' => $C->invoice_number,
        'D' => $D->invoice_number, 'E' => $E->invoice_number, 'F' => $F->invoice_number];

    echo "\n=== 1. Klasifikasi tiap jalur ===\n";

    $posisiDari = function (BsApInvoice $inv) {
        foreach ([P::HUTANG, P::BEBAN] as $p) {
            if (P::saring(BsApInvoice::whereKey($inv->id), $p)->exists()) { return $p; }
        }

        return null;
    };

    $ok('A kategori hutang → Hutang Usaha', $posisiDari($A) === P::HUTANG);
    $ok('B kategori beban → BYMHD', $posisiDari($B) === P::BEBAN);
    $ok('C tanpa kategori, akun hutang usaha → Hutang Usaha', $posisiDari($C) === P::HUTANG,
        'invoice Saldo Awal & hasil Posting hilang dari kedua pilihan');
    $ok('D tanpa kategori, akun beban YMHD → BYMHD', $posisiDari($D) === P::BEBAN);
    $ok('E kategori sudah dihapus → tetap mendarat di salah satu', $posisiDari($E) !== null,
        'baris dengan kategori terhapus lenyap dari kedua pilihan');
    // Kategori yang sudah dihapus TIDAK punya posisi lagi; yang menentukan adalah akun yang
    // benar-benar dikredit jurnalnya. Memakai whereNull('category_id') sebagai ganti
    // whereDoesntHave('category') membuat baris ini salah dilabeli Hutang Usaha.
    $ok('F kategori terhapus + akun Beban YMHD → BYMHD', $posisiDari($F) === P::BEBAN,
        'kategori terhapus masih dianggap ada — posisinya dibaca dari kategori yang tak terjangkau');

    $hitung = fn (string $p) => P::saring(BsApInvoice::where('invoice_number', 'like', 'INV-'.$TAG.'%'), $p)->count();
    $ok('Tampilkan Semua = Hutang Usaha + BYMHD',
        $hitung(P::SEMUA) === $hitung(P::HUTANG) + $hitung(P::BEBAN)
        && $hitung(P::SEMUA) === 6,
        'semua='.$hitung(P::SEMUA).' hutang='.$hitung(P::HUTANG).' bymhd='.$hitung(P::BEBAN));
    $ok('  nilai pilihan yang tidak dikenal jatuh ke Tampilkan Semua', P::sah('apa-saja') === P::SEMUA);
    $ok('  kosong pun jatuh ke Tampilkan Semua', P::sah(null) === P::SEMUA && P::sah('') === P::SEMUA);

    $ctlAp = new \App\Http\Controllers\Web\BusinessSupport\Payable\ApInvoiceController();

    echo "\n=== 2. Aging Hutang (layar + Excel + PDF) ===\n";

    $agingTotal = function (string $p) use ($props, $ctlAp) {
        $d = $props(fn ($r) => $ctlAp->aging($r), ['pull' => 1, 'posisi' => $p]);

        return round((float) ($d['totals']['total'] ?? 0), 2);
    };
    $aS = $agingTotal(P::SEMUA); $aH = $agingTotal(P::HUTANG); $aB = $agingTotal(P::BEBAN);
    $ok('total Semua = Hutang + BYMHD', abs($aS - ($aH + $aB)) < 0.01, "semua=$aS hutang=$aH bymhd=$aB");
    $ok('  BYMHD memuat B, D & F saja', abs($aB - 3_100_000) < 0.01, 'bymhd='.$aB);
    $ok('  layar mengingat pilihannya',
        ($props(fn ($r) => $ctlAp->aging($r), ['pull' => 1, 'posisi' => P::BEBAN])['posisi'] ?? '') === P::BEBAN);
    $ok('  daftar pilihannya dikirim ke layar',
        count($props(fn ($r) => $ctlAp->aging($r), ['pull' => 1])['posisiOpsi'] ?? []) === 3);

    foreach ([['Excel', 'agingExportExcel'], ['PDF', 'agingExportPdf']] as [$nama, $method]) {
        $bymhd = $isi(fn ($r) => $ctlAp->$method($r), ['posisi' => P::BEBAN]);
        $ok("  ekspor $nama ikut tersaring",
            str_contains($bymhd, $semuaNo['B']) && ! str_contains($bymhd, $semuaNo['A']),
            "berkas $nama memuat seluruh posisi sementara layarnya tersaring");
    }

    echo "\n=== 3. Aging Hutang Supplier ===\n";

    $supTotal = function (string $p) use ($props, $ctlAp) {
        $d = $props(fn ($r) => $ctlAp->agingSupplier($r), ['posisi' => $p]);

        return round((float) ($d['total']['total'] ?? 0), 2);
    };
    $ok('total Semua = Hutang + BYMHD',
        abs($supTotal(P::SEMUA) - ($supTotal(P::HUTANG) + $supTotal(P::BEBAN))) < 0.01);
    $ok('  angkanya sama dengan Aging Hutang pada pilihan yang sama',
        abs($supTotal(P::BEBAN) - $aB) < 0.01,
        'dua layar yang menjanjikan angka sama justru berbeda saat disaring');

    echo "\n=== 4. Kartu Hutang (layar + Excel + PDF) ===\n";

    $kartu = function (string $p) use ($props, $ctlAp, $supplierId) {
        $d = $props(fn ($r) => $ctlAp->card($r), ['view' => 'summary', 'supplier_id' => $supplierId, 'posisi' => $p]);

        return round((float) collect($d['summary'] ?? [])->sum('outstanding'), 2);
    };
    $ok('outstanding Semua = Hutang + BYMHD',
        abs($kartu(P::SEMUA) - ($kartu(P::HUTANG) + $kartu(P::BEBAN))) < 0.01,
        'semua='.$kartu(P::SEMUA).' hutang='.$kartu(P::HUTANG).' bymhd='.$kartu(P::BEBAN));
    foreach ([['Excel', 'cardExportExcel'], ['PDF', 'cardExportPdf']] as [$nama, $method]) {
        $bymhd = $isi(fn ($r) => $ctlAp->$method($r), ['view' => 'detail', 'supplier_id' => $supplierId, 'posisi' => P::BEBAN]);
        $ok("  ekspor $nama ikut tersaring",
            str_contains($bymhd, $semuaNo['B']) && ! str_contains($bymhd, $semuaNo['A']));
    }

    echo "\n=== 5. Laporan Invoice Hutang (layar + berkas) ===\n";

    $rep = fn (string $p) => collect($props(fn ($r) => $ctlAp->report($r), ['pull' => 1, 'posisi' => $p])['rows'] ?? [])
        ->pluck('invoice_no')->all();
    $ok('baris Semua = Hutang + BYMHD',
        count($rep(P::SEMUA)) === count($rep(P::HUTANG)) + count($rep(P::BEBAN)));
    $ok('  memilih BYMHD membuang invoice hutang usaha',
        in_array($semuaNo['B'], $rep(P::BEBAN), true) && ! in_array($semuaNo['A'], $rep(P::BEBAN), true));
    $berkas = $isi(fn ($r) => $ctlAp->export($r), ['posisi' => P::BEBAN]);
    $ok('  berkas ekspornya ikut tersaring (query terpisah dari layar)',
        str_contains($berkas, $semuaNo['B']) && ! str_contains($berkas, $semuaNo['A']));

    echo "\n=== 6. Kartu Pembayaran Hutang (termasuk angka Total) ===\n";

    $akunKas = BsAccount::where('code', '<>', $akunHutang->code)->orderBy('id')->firstOrFail();
    foreach ([[$A, 300_000], [$B, 500_000], [$D, 100_000]] as [$inv, $nilai]) {
        DB::table('bs_ap_payments')->insert([
            'ap_invoice_id' => $inv->id, 'payment_date' => now()->toDateString(),
            'cash_account_id' => $akunKas->id, 'amount' => $nilai, 'reference' => 'BYR-'.$TAG,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $bayar = function (string $p) use ($props, $ctlAp, $supplierId) {
        $d = $props(fn ($r) => $ctlAp->paymentCard($r), ['supplier_id' => $supplierId, 'posisi' => $p]);

        return [round((float) ($d['total'] ?? 0), 2), count($d['payments']['data'] ?? [])];
    };
    [$tS, $nS] = $bayar(P::SEMUA); [$tH, $nH] = $bayar(P::HUTANG); [$tB, $nB] = $bayar(P::BEBAN);
    $ok('total Semua = Hutang + BYMHD', abs($tS - ($tH + $tB)) < 0.01, "semua=$tS hutang=$tH bymhd=$tB");
    $ok('  jumlah barisnya pun', $nS === $nH + $nB);
    // Jebakan khusus layar ini: 'total' dihitung query SENDIRI, bukan dari baris yang tampil.
    $ok('  Total tidak melebihi jumlah baris yang ditampilkan', abs($tB - 600_000) < 0.01,
        'angka Total dihitung dari query yang tidak ikut disaring — bymhd='.$tB);
    foreach ([['Excel', 'paymentCardExportExcel'], ['PDF', 'paymentCardExportPdf']] as [$nama, $method]) {
        $bymhd = $isi(fn ($r) => $ctlAp->$method($r), ['supplier_id' => $supplierId, 'posisi' => P::BEBAN]);
        $ok("  ekspor $nama ikut tersaring",
            str_contains($bymhd, $semuaNo['B']) && ! str_contains($bymhd, $semuaNo['A']));
    }

    echo "\n=== 7. Proyeksi Hutang ===\n";

    $ctlProj = new \App\Http\Controllers\Web\BusinessSupport\Report\ProjectionController();
    $proj = function (string $p, string $mode = 'hutang') use ($props, $ctlProj) {
        $d = $props(fn ($r) => $ctlProj->index($r), ['mode' => $mode, 'posisi' => $p]);

        return round((float) ($d['totals']['total'] ?? 0), 2);
    };
    $ok('total Semua = Hutang + BYMHD',
        abs($proj(P::SEMUA) - ($proj(P::HUTANG) + $proj(P::BEBAN))) < 0.01,
        'semua='.$proj(P::SEMUA).' hutang='.$proj(P::HUTANG).' bymhd='.$proj(P::BEBAN));
    // Cabang piutang membaca invoice AR yang tidak punya kategori AP — pilihan ini tidak
    // boleh berlaku di sana, kalau tidak angkanya berubah tanpa alasan yang bisa dijelaskan.
    $ok('  cabang PIUTANG tidak ikut tersaring',
        abs($proj(P::BEBAN, 'piutang') - $proj(P::SEMUA, 'piutang')) < 0.01);
    $ok('  dan pilihannya dipaksa "semua" di cabang piutang',
        ($props(fn ($r) => $ctlProj->index($r), ['mode' => 'piutang', 'posisi' => P::BEBAN])['filters']['posisi'] ?? '') === P::SEMUA);

    echo "\n=== 8. Laporan Penghapusan Hutang ===\n";

    $ctlPeng = new \App\Http\Controllers\Web\BusinessSupport\Payable\PenghapusanController();
    foreach ([[$A, 50_000, 'M1'], [$B, 70_000, 'M2']] as [$inv, $nilai, $no]) {
        DB::table('bs_ap_memorials')->insert([
            'tenant_id' => $tenant, 'memorial_number' => 'MEM-'.$TAG.'-'.$no, 'memorial_date' => now()->toDateString(),
            'ap_invoice_id' => $inv->id, 'supplier_id' => $supplierId, 'trx_type' => 'penghapusan',
            'counter_account_id' => $akunBiaya->id, 'payable_account_id' => $akunHutang->id,
            'amount' => $nilai, 'description' => 'Uji hapus', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    // Penghapusan TANPA invoice — ap_invoice_id nullable. whereHas yang dipasang pada pilihan
    // "Tampilkan Semua" akan membuangnya, dan uangnya hilang dari laporan tanpa satu pun tanda.
    DB::table('bs_ap_memorials')->insert([
        'tenant_id' => $tenant, 'memorial_number' => 'MEM-'.$TAG.'-M3', 'memorial_date' => now()->toDateString(),
        'ap_invoice_id' => null, 'supplier_id' => $supplierId, 'trx_type' => 'penghapusan',
        'counter_account_id' => $akunBiaya->id, 'payable_account_id' => $akunHutang->id,
        'amount' => 90_000, 'description' => 'Uji hapus tanpa invoice', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $hapus = function (string $p) use ($props, $ctlPeng, $TAG) {
        $d = $props(fn ($r) => $ctlPeng->report($r), ['posisi' => $p]);
        $rows = collect($d['rows'] ?? [])->filter(fn ($x) => str_contains((string) $x['memorial_number'], $TAG));

        return [$rows->pluck('memorial_number')->all(), round((float) $rows->sum('amount'), 2)];
    };
    [$noS, $jS] = $hapus(P::SEMUA); [$noH, $jH] = $hapus(P::HUTANG); [$noB, $jB] = $hapus(P::BEBAN);
    $ok('penghapusan TANPA invoice tetap tampil pada Tampilkan Semua',
        in_array('MEM-'.$TAG.'-M3', $noS, true),
        'whereHas dipasang juga pada "semua" — uang yang dihapus hilang dari laporan');
    $ok('  memilih BYMHD hanya menyisakan penghapusan invoice BYMHD',
        $noB === ['MEM-'.$TAG.'-M2'] && abs($jB - 70_000) < 0.01);
    $ok('  memilih Hutang Usaha hanya menyisakan penghapusan invoice hutang usaha',
        $noH === ['MEM-'.$TAG.'-M1'] && abs($jH - 50_000) < 0.01);
    $ok('  Total layar dihitung dari baris yang ditampilkan',
        abs($jS - 210_000) < 0.01, 'total semua='.$jS);

    echo "\n=== 9. Rekap PPh Dipotong ===\n";

    // Rekap PPh digerakkan BUKU BESAR, jadi datanya harus berupa jurnal — bukan invoice.
    // Tiga baris sengaja: satu menempel pada invoice BYMHD, satu pada invoice Hutang Usaha, dan
    // satu jurnal manual TANPA invoice (meniru PPh 21 Payroll). Tanpa baris ketiga itu, angka
    // "disisihkan" selalu nol dan pemeriksaannya tidak menguji apa pun.
    $akunPph = BsAccount::where('code', '20104040403')->firstOrFail();
    $jurnal = function (string $no, ?BsApInvoice $inv, float $nilai) use ($tenant, $akunPph, $akunBiaya) {
        $jid = (int) DB::table('bs_journals')->insertGetId([
            'tenant_id' => $tenant, 'journal_number' => $no, 'journal_date' => now()->toDateString(),
            'period_year' => (int) now()->year, 'period_month' => (int) now()->month,
            'description' => 'Uji PPh', 'status' => 'posted', 'source' => 'manual',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('bs_journal_lines')->insert([
            ['journal_id' => $jid, 'account_id' => $akunPph->id, 'debit' => 0, 'credit' => $nilai],
            ['journal_id' => $jid, 'account_id' => $akunBiaya->id, 'debit' => $nilai, 'credit' => 0],
        ]);
        if ($inv) { $inv->forceFill(['journal_id' => $jid])->save(); }

        return $jid;
    };
    $jurnal('JV-'.$TAG.'-B', $B, 11_000);
    $jurnal('JV-'.$TAG.'-A', $A, 22_000);
    $jurnal('JV-'.$TAG.'-X', null, 33_000);

    $ctlPph = new \App\Http\Controllers\Web\BusinessSupport\Tax\PphController(
        app(\App\Domain\BusinessSupport\Services\BukuBesarDokumen::class),
        app(\App\Domain\BusinessSupport\Services\JournalPartyResolver::class),
    );
    $pph = fn (string $p) => $props(fn ($r) => $ctlPph->index($r), ['posisi' => $p]);
    $dS = $pph(P::SEMUA); $dB = $pph(P::BEBAN);
    $ok('layar mengingat pilihannya', ($dB['posisi'] ?? '') === P::BEBAN);
    $ok('  daftar pilihannya dikirim', count($dB['posisiOpsi'] ?? []) === 3);
    $ok('  memilih BYMHD hanya menyisakan PPh dari invoice BYMHD',
        collect($dB['rows'] ?? [])->where('doc_no', $semuaNo['B'])->count() === 1
        && collect($dB['rows'] ?? [])->where('doc_no', $semuaNo['A'])->count() === 0,
        'baris yang tersisa: '.collect($dB['rows'] ?? [])->pluck('doc_no')->implode(', '));
    $ok('  baris tanpa invoice hutang DILAPORKAN jumlahnya, bukan hilang diam-diam',
        array_key_exists('disisihkan', $dB)
        && (int) $dB['disisihkan'] === count($dS['rows'] ?? []) - count($dB['rows'] ?? [])
        && (int) $dB['disisihkan'] >= 2,
        'disisihkan='.($dB['disisihkan'] ?? 'tidak ada').' semua='.count($dS['rows'] ?? []).' bymhd='.count($dB['rows'] ?? []));
    $ok('  "Tampilkan Semua" tidak menyisihkan apa pun', (int) ($dS['disisihkan'] ?? -1) === 0);
    // Kartu cross-check GL sengaja tetap dihitung dari SELURUH posisi; kalau tidak ditandai,
    // centang hijaunya hilang saat menyaring dan terbaca sebagai selisih pembukuan.
    $ok('  kartu cross-check GL ditandai berbicara untuk seluruh posisi',
        ($dB['glSeluruhPosisi'] ?? null) === true && ($dS['glSeluruhPosisi'] ?? null) === false);

    echo "\n=== 10. Kontrolnya benar-benar terpasang di kedelapan layar ===\n";

    $layar = [
        'Payable/Aging.vue', 'Payable/AgingSupplier.vue', 'Payable/Card.vue',
        'Payable/PaymentCard.vue', 'Payable/InvoiceReport.vue', 'Payable/PenghapusanReport.vue',
        'Report/Projection.vue', 'Tax/Pph.vue',
    ];
    foreach ($layar as $v) {
        $src = file_get_contents(base_path('resources/js/Pages/BusinessSupport/'.$v));
        $ok('  '.basename($v).' memakai kontrol bersama',
            str_contains($src, '<PilihPosisiHutang') && str_contains($src, "@/Components/UI/PilihPosisiHutang.vue"),
            'layar menggambar dropdown-nya sendiri — istilah & daftar pilihannya bisa berbeda');
        $ok('    dan mengirim posisi saat memuat ulang', str_contains($src, 'posisi'));
    }

    // Tautan ekspor membaca props.*, bukan ref yang sedang disentuh operator: ref berubah lebih
    // dulu daripada layarnya, sehingga berkas yang terunduh di sela itu berbeda dari yang tampil.
    foreach (['Payable/Aging.vue', 'Payable/Card.vue', 'Payable/PaymentCard.vue'] as $v) {
        $src = file_get_contents(base_path('resources/js/Pages/BusinessSupport/'.$v));
        $ok('  '.basename($v).' merakit URL ekspor dari props, bukan ref',
            str_contains($src, 'paramEkspor') && str_contains($src, "posisi: props.posisi ?? 'semua'"));
    }

    $komp = file_get_contents(base_path('resources/js/Components/UI/PilihPosisiHutang.vue'));
    preg_match('/const BAWAAN.*?=\s*\[(.*?)\];/s', $komp, $m);
    $bawaan = $m[1] ?? '';
    $ok('kontrolnya menawarkan ketiga pilihan',
        substr_count($bawaan, 'nilai:') === 3
        && str_contains($bawaan, "'hutang'") && str_contains($bawaan, "'beban_ymhd'") && str_contains($bawaan, "'semua'"),
        'daftar cadangannya: '.trim(preg_replace('/s+/', ' ', $bawaan)));
    $ok('  daftar pilihannya datang dari server', str_contains($komp, 'props.opsi'));

    $tuntas = true;
} catch (\Throwable $e) {
    $gagal++;
    echo "\n  GAGAL (galat tak tertangani) — ".get_class($e).': '.$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n";
} finally {
    DB::rollBack();

    echo "\n────────────────────────────────\n";
    echo "LULUS: $lulus   GAGAL: $gagal\n";
    echo ($gagal === 0 && $tuntas) ? "SEMUA UJI LULUS\n" : "ADA YANG GAGAL\n";
    echo "(data uji dibatalkan)\n";
}

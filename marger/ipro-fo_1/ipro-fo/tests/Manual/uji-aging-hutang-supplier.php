<?php

/**
 * AGING HUTANG SUPPLIER — outstanding per supplier, 20 terbesar + rangkuman "Others".
 *
 * APA YANG DIJAGA DAN KENAPA.
 *   1. TOP 20 + OTHERS SELALU SAMA DENGAN TOTAL. Tanpa baris Others, tabel yang memuat 20 dari
 *      puluhan supplier terbaca seolah itulah seluruh hutangnya — dan jumlahnya tetap terlihat
 *      wajar, jadi tidak ada yang memberi tahu.
 *   2. JUMLAH KETIGA EMBER SELALU SAMA DENGAN OUTSTANDING. Lima ember computeAging dirangkum
 *      jadi tiga; yang BELUM jatuh tempo masuk ke 0–30, bukan dibuang. Membuangnya membuat
 *      selisihnya tidak pernah dijelaskan di mana pun.
 *   3. ANGKANYA SAMA DENGAN LAYAR AGING HUTANG. Keduanya memakai computeAging() yang sama;
 *      uji ini membandingkannya langsung, bukan mengandaikan.
 *   4. DIURUTKAN MENURUN. "Top 20" tidak berarti apa-apa bila urutannya bukan dari yang
 *      terbesar.
 *   5. OTHERS TIDAK MUNCUL bila supplier-nya 20 atau kurang — baris rangkuman kosong hanya
 *      membuat orang mengira ada yang disembunyikan.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-aging-hutang-supplier.php';"
 */

use App\Http\Controllers\Web\BusinessSupport\Payable\ApInvoiceController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};
$rp = fn ($v) => (int) round((float) $v);

$user = User::all()->first(fn ($u) => $u->can('ap.aging.view')) ?? User::first();
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
if (! $user->can('ap.aging.view')) { echo "User tanpa izin ap.aging.view — uji dihentikan.\n"; return; }
auth()->setUser($user);

$ctrl = app(ApInvoiceController::class);

$props = function (string $method, array $q = []) use ($ctrl, $user): array {
    $r = Request::create('/x', 'GET', $q, [], [], ['HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $r->setUserResolver(fn () => $user);
    app()->instance('request', $r);

    return $ctrl->{$method}($r)->toResponse($r)->getData(true)['props'];
};

$tenant = tenant_id() ?? default_tenant_id();
$akunHutang = DB::table('bs_accounts')->where('is_postable', 1)->where('type', 'liability')->value('id');
if (! $akunHutang) { echo "Akun hutang tidak ada — uji dihentikan.\n"; return; }

$ASOF = '2047-06-30';

DB::beginTransaction();
DB::statement('SET FOREIGN_KEY_CHECKS=0');

try {
    /* Basis data ini SUDAH berisi hutang nyata, jadi yang bisa ditegaskan adalah SELISIHNYA.
       Angka mutlak akan berubah setiap kali data lain ikut tercatat — ujinya lalu merah karena
       sebab yang tidak ada hubungannya dengan yang sedang diuji. */
    $awal = $props('agingSupplier', ['date_to' => $ASOF]);
    $supplierAwal = (int) $awal['total']['jumlah'];
    $totalAwal    = $rp($awal['total']['total']);

    $seq = 0;
    /** Supplier + satu invoice hutang dengan umur tertentu (hari lewat jatuh tempo). */
    $buat = function (string $nama, float $nilai, int $lewat) use (&$seq, $tenant, $akunHutang, $ASOF): void {
        $seq++;
        $supplierId = 970000 + $seq;
        DB::table('bs_suppliers')->insert([
            'id' => $supplierId, 'tenant_id' => $tenant, 'code' => 'UJI-S'.$seq, 'name' => $nama,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $jatuhTempo = \Illuminate\Support\Carbon::parse($ASOF)->subDays($lewat)->toDateString();
        DB::table('bs_ap_invoices')->insert([
            'tenant_id' => $tenant, 'supplier_id' => $supplierId,
            'invoice_number' => 'UJI-INV-'.$seq, 'invoice_date' => '2047-01-01',
            // payable_amount adalah ACCESSOR atas net_payable, bukan kolom — lihat BsApInvoice.
            'due_date' => $jatuhTempo, 'total' => $nilai, 'net_payable' => $nilai,
            'expense_account_id' => $akunHutang, 'payable_account_id' => $akunHutang,
            'description' => 'Uji aging supplier',
            'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    echo "\n=== 1. TIGA EMBER MERANGKUM LIMA EMBER computeAging ===\n";

    /* lewat = 0 → belum jatuh tempo; 10 → 1–30; 45 → 31–60; 120 → >90. */
    $buat('UJI Alpha', 1_000_000, 0);    // belum JT → 0–30
    $buat('UJI Beta', 2_000_000, 10);    // 1–30     → 0–30
    $buat('UJI Gamma', 3_000_000, 45);   // 31–60    → 31–60
    $buat('UJI Delta', 4_000_000, 120);  // >90      → >60

    $p = $props('agingSupplier', ['date_to' => $ASOF]);
    $r = collect($p['rows'])->keyBy('name');

    $ok('belum jatuh tempo masuk ke 0–30', $rp($r['UJI Alpha']['b0']) === 1_000_000, json_encode($r['UJI Alpha'] ?? null));
    $ok('1–30 hari lewat masuk ke 0–30', $rp($r['UJI Beta']['b0']) === 2_000_000, json_encode($r['UJI Beta'] ?? null));
    $ok('31–60 masuk ke embernya sendiri', $rp($r['UJI Gamma']['b31']) === 3_000_000, json_encode($r['UJI Gamma'] ?? null));
    $ok('>90 masuk ke > 60', $rp($r['UJI Delta']['b60']) === 4_000_000, json_encode($r['UJI Delta'] ?? null));

    /* Inilah yang membuat tabelnya bisa dipercaya: ketiga kolom selalu menjumlah ke Outstanding. */
    foreach ($p['rows'] as $baris) {
        $jml = $rp($baris['b0']) + $rp($baris['b31']) + $rp($baris['b60']);
        if ($jml !== $rp($baris['total'])) {
            $ok('jumlah tiga ember = Outstanding untuk '.$baris['name'], false, json_encode($baris));
            break;
        }
    }
    $ok('jumlah tiga ember = Outstanding pada SEMUA baris',
        collect($p['rows'])->every(fn ($b) => $rp($b['b0']) + $rp($b['b31']) + $rp($b['b60']) === $rp($b['total'])));

    echo "\n=== 2. DIURUTKAN MENURUN ===\n";

    $urut = collect($p['rows'])->pluck('total')->map($rp)->all();
    $ok('baris diurutkan dari hutang terbesar', $urut === collect($urut)->sortDesc()->values()->all(), json_encode($urut));
    $ok('supplier dominan disebut namanya', ($p['dominan']['name'] ?? '') === 'UJI Delta', json_encode($p['dominan'] ?? null));

    echo "\n=== 3. OTHERS BELUM MUNCUL SAAT SUPPLIER ≤ 20 ===\n";

    $ok('supplier bertambah 4', (int) $p['total']['jumlah'] - $supplierAwal === 4,
        json_encode([$supplierAwal, $p['total']['jumlah']]));
    $ok('TOTAL bertambah persis 10 juta', $rp($p['total']['total']) - $totalAwal === 10_000_000,
        json_encode([$totalAwal, $p['total']['total']]));
    $ok('others belum muncul selama supplier masih ≤ 20',
        (int) $p['total']['jumlah'] <= 20 ? $p['others'] === null : true, json_encode($p['others']));

    echo "\n=== 4. LEBIH DARI 20 SUPPLIER → OTHERS MERANGKUM SISANYA ===\n";

    /* 21 supplier tambahan bernilai kecil supaya seluruhnya jatuh di luar Top 20. */
    for ($i = 1; $i <= 21; $i++) {
        $buat('UJI Kecil '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 1_000 * $i, 10);
    }

    $p2 = $props('agingSupplier', ['date_to' => $ASOF]);

    $ok('baris yang ditampilkan tepat 20', count($p2['rows']) === 20, (string) count($p2['rows']));
    $ok('others muncul', $p2['others'] !== null);
    $ok('supplier bertambah 21 lagi', (int) $p2['total']['jumlah'] - $supplierAwal === 25,
        json_encode([$supplierAwal, $p2['total']['jumlah']]));
    /* Others = seluruh sisanya, berapa pun jumlah supplier yang sudah ada di basis data. */
    $ok('others merangkum PERSIS supplier di luar Top 20',
        (int) ($p2['others']['jumlah'] ?? 0) === (int) $p2['total']['jumlah'] - 20,
        json_encode([$p2['others']['jumlah'] ?? null, $p2['total']['jumlah']]));

    /* PENJAGA UTAMA: Top 20 + Others harus SELALU sama dengan TOTAL. */
    $top = fn (string $k) => $rp(collect($p2['rows'])->sum($k));
    foreach (['b0', 'b31', 'b60', 'total'] as $k) {
        $ok("Top 20 + Others = TOTAL pada kolom {$k}",
            $top($k) + $rp($p2['others'][$k]) === $rp($p2['total'][$k]),
            json_encode(['top' => $top($k), 'others' => $p2['others'][$k], 'total' => $p2['total'][$k]]));
    }

    $ok('supplier terkecil benar-benar masuk Others, bukan hilang',
        ! collect($p2['rows'])->contains(fn ($b) => $b['name'] === 'UJI Kecil 01'),
        'UJI Kecil 01 justru tampil di Top 20');

    echo "\n=== 5. ANGKANYA SAMA DENGAN LAYAR AGING HUTANG ===\n";

    /* Dua layar yang menghitung hutang dengan cara berbeda akan saling membantah. Diperiksa
       langsung terhadap layar Aging Hutang, bukan diandaikan. */
    $ag = $props('aging', ['date_to' => $ASOF, 'pull' => 1]);
    $ok('total outstanding sama dengan layar Aging Hutang',
        $rp($ag['totals']['total']) === $rp($p2['total']['total']),
        json_encode(['aging' => $ag['totals']['total'], 'supplier' => $p2['total']['total']]));
    $ok('jumlah supplier sama dengan jumlah baris Aging Hutang',
        count($ag['rows']) === (int) $p2['total']['jumlah'],
        json_encode([count($ag['rows']), $p2['total']['jumlah']]));

    /* Pemetaan embernya juga harus tepat, bukan sekadar totalnya kebetulan sama. */
    $ok('0–30 = belum JT + 1–30 dari Aging Hutang',
        $rp($ag['totals']['current']) + $rp($ag['totals']['d30']) === $rp($p2['total']['b0']),
        json_encode([$ag['totals']['current'], $ag['totals']['d30'], $p2['total']['b0']]));
    $ok('> 60 = 61–90 + >90 dari Aging Hutang',
        $rp($ag['totals']['d90']) + $rp($ag['totals']['d90p']) === $rp($p2['total']['b60']),
        json_encode([$ag['totals']['d90'], $ag['totals']['d90p'], $p2['total']['b60']]));

    echo "\n=== 6. INVOICE LUNAS TIDAK IKUT ===\n";

    $seq++;
    $sLunas = 970000 + $seq;
    DB::table('bs_suppliers')->insert([
        'id' => $sLunas, 'tenant_id' => $tenant, 'code' => 'UJI-LUNAS', 'name' => 'UJI Sudah Lunas',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $invLunas = DB::table('bs_ap_invoices')->insertGetId([
        'tenant_id' => $tenant, 'supplier_id' => $sLunas, 'invoice_number' => 'UJI-INV-LUNAS',
        'invoice_date' => '2047-01-01', 'due_date' => '2047-02-01',
        'total' => 5_000_000, 'net_payable' => 5_000_000, 'description' => 'Uji lunas',
        'expense_account_id' => $akunHutang, 'payable_account_id' => $akunHutang,
        'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
    ]);
    // bs_ap_payments tidak punya tenant_id — ia mengikuti invoice induknya.
    DB::table('bs_ap_payments')->insert([
        'ap_invoice_id' => $invLunas, 'payment_date' => '2047-03-01',
        'cash_account_id' => $akunHutang, 'amount' => 5_000_000,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $p3 = $props('agingSupplier', ['date_to' => $ASOF]);
    $ok('supplier yang hutangnya sudah lunas tidak muncul di Top 20',
        ! collect($p3['rows'])->contains(fn ($b) => $b['name'] === 'UJI Sudah Lunas'));
    $ok('dan tidak menambah jumlah supplier sama sekali',
        (int) $p3['total']['jumlah'] === (int) $p2['total']['jumlah'],
        json_encode([$p2['total']['jumlah'], $p3['total']['jumlah']]));
    $ok('serta tidak menambah nilai TOTAL', $rp($p3['total']['total']) === $rp($p2['total']['total']),
        json_encode([$p2['total']['total'], $p3['total']['total']]));

    echo "\n──────────────────────────────────────────────────────────────────────────────\n";
    echo $gagal === 0 ? "SEMUA LULUS ($lulus)\n" : "ADA YANG GAGAL — lulus $lulus, gagal $gagal\n";
} finally {
    DB::rollBack();
    echo "(data uji dibatalkan)\n";
}

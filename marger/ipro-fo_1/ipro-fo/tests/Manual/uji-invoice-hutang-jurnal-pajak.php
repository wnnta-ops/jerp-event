<?php

/**
 * Registrasi Invoice Hutang — jurnal PPN & PPh yang BENAR-BENAR TERPOSTING.
 *
 * Aturan strukturnya sendiri (sisi layar) diuji di tests/Manual/uji-struktur-jurnal-hutang.cjs.
 * Berkas ini menutup ujung yang lain: struktur yang dikirim wizard harus diterima apa adanya dan
 * membentuk baris jurnal yang sama persis, DAN jalur otomatis di server (saat struktur tidak
 * dikirim sama sekali — mis. dari API atau layar lain) harus menghasilkan angka yang sama.
 *
 * KENAPA KEDUANYA DIUJI BERSAMA. Aturannya ada di DUA tempat: bangunStrukturJurnal() di layar dan
 * jalur otomatis di RegisterApInvoice. Bila keduanya berbeda, invoice yang sama akan berjurnal
 * berbeda tergantung dari pintu mana ia masuk — dan keduanya tetap balance, jadi tidak ada satu
 * pun galat yang memberi tahu.
 *
 *   1. Exclude PPN    Dr Beban DPP · Dr PPN Masukan · Cr Hutang Usaha (DPP + PPN)
 *   2. Include PPN    Dr Beban (Nilai − PPN) · Dr PPN Masukan · Cr Hutang Usaha Nilai
 *   3. PPh dipotong   Cr Hutang PPh; Cr Hutang Usaha berkurang sebesar PPh
 *   4. PPh ditanggung Dr Biaya PPh · Cr Hutang PPh; Cr Hutang Usaha TIDAK berubah
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-invoice-hutang-jurnal-pajak.php';"
 */

use App\Domain\BusinessSupport\Actions\RegisterApInvoice;
use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};

$user = User::all()->first(fn ($u) => $u->canModule('ap')) ?? User::first();
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
auth()->setUser($user);

$aksi = app(RegisterApInvoice::class);

$akun = fn (string $kode) => BsAccount::where('code', $kode)->value('id');
$AKUN_PPN     = $akun(config('business_support.gl_accounts.ppn_in'));
// Akun Biaya PPh ditanggung kini PER JENIS PPh. Skenario 4 di bawah memakai PPh 23, jadi
// yang dinanti akun BIAYA PPH 23 — bukan lagi satu akun beban umum untuk semua jenis.
$akunBiayaPph = fn (string $jenis) => $akun(
    config('business_support.gl_accounts.pph_borne_expense')[$jenis]
    ?? config('business_support.gl_accounts.pph_borne_expense_default')
);
$AKUN_PPH_BIAYA = $akunBiayaPph('pph23');
$AKUN_HUTANG  = $akun('20101010101');
$AKUN_PPH23   = $akun('20104040403');
$AKUN_BEBAN   = BsAccount::where('is_postable', true)->where('type', 'expense')
    // KETIGA akun Biaya PPh dikecualikan, bukan hanya yang dipakai skenario 4: kalau akun beban
    // umum kebetulan jatuh pada salah satunya, baris Beban dan baris Biaya PPh akan menumpuk di
    // akun yang sama dan pemeriksaan nominalnya jadi tidak berarti apa-apa.
    ->whereNotIn('id', array_filter(array_map($akunBiayaPph, ['pph21', 'pph23', 'pph4_2'])))
    ->orderBy('code')->value('id');

echo "\n=== PRASYARAT ===\n";
$ok('akun PPN Masukan ada', (bool) $AKUN_PPN);
$ok('akun Utang Usaha Supplier ada', (bool) $AKUN_HUTANG);
$ok('akun Hutang PPh 23 ada', (bool) $AKUN_PPH23);
$ok('akun Biaya PPh 23 ditanggung ada', (bool) $AKUN_PPH_BIAYA);
$ok('akun beban ada', (bool) $AKUN_BEBAN);
if (! $AKUN_PPN || ! $AKUN_HUTANG || ! $AKUN_PPH23 || ! $AKUN_PPH_BIAYA || ! $AKUN_BEBAN) {
    echo "\nPrasyarat kurang.\n";

    return;
}

DB::beginTransaction();

try {
    app(\App\Domain\BusinessSupport\Services\JournalService::class)->ensurePeriod((int) now()->year, (int) now()->month);

    $supplier = BsSupplier::first();
    if (! $supplier) {
        $supplier = BsSupplier::create(['code' => 'UJI-SUP-JP', 'name' => 'UJI SUPPLIER JURNAL PAJAK', 'is_active' => true]);
    }

    $ke = 0;
    /** Daftarkan invoice; kembalikan baris jurnalnya sebagai [account_id => [debit, credit]]. */
    $daftar = function (array $data) use ($aksi, $supplier, $user, &$ke) {
        $ke++;
        $inv = $aksi->execute([
            'supplier_id'         => $supplier->id,
            'supplier_invoice_no' => 'UJI-JP-'.$ke,
            'invoice_date'        => now()->toDateString(),
            'receive_date'        => now()->toDateString(),
            'term_days'           => 30,
            'description'         => 'uji jurnal pajak '.$ke,
        ] + $data, $user->id);

        $baris = BsJournal::with('lines')->find($inv->journal_id)?->lines ?? collect();

        // Dibulatkan ke INT: round() mengembalikan float, dan perbandingan ketat float-vs-int
        // akan selalu gagal walaupun angkanya sama persis.
        return [$inv, $baris->mapWithKeys(fn ($l) => [(int) $l->account_id => [
            'debit' => (int) round((float) $l->debit), 'credit' => (int) round((float) $l->credit),
        ]])->all()];
    };
    $seimbang = function (array $peta): bool {
        $d = array_sum(array_column($peta, 'debit'));
        $k = array_sum(array_column($peta, 'credit'));

        return abs($d - $k) < 0.01;
    };

    echo "\n=== 1. EXCLUDE PPN — jalur otomatis server ===\n";

    // Nilai transaksi 1.000 · PPN 11% = 110 → Hutang Usaha 1.110.
    [$inv1, $j1] = $daftar([
        'expense_account_id' => $AKUN_BEBAN, 'payable_account_id' => $AKUN_HUTANG,
        'dpp' => 1000, 'ppn_mode' => 'with', 'ppn_pct' => 11, 'ppn_base' => 1000,
    ]);
    $ok('Dr beban = 1.000', ($j1[$AKUN_BEBAN]['debit'] ?? 0) === 1000, json_encode($j1[$AKUN_BEBAN] ?? null));
    $ok('Dr PPN Masukan = 110', ($j1[$AKUN_PPN]['debit'] ?? 0) === 110, json_encode($j1[$AKUN_PPN] ?? null));
    $ok('Cr Hutang Usaha BERTAMBAH sebesar PPN → 1.110',
        ($j1[$AKUN_HUTANG]['credit'] ?? 0) === 1110, json_encode($j1[$AKUN_HUTANG] ?? null));
    $ok('jurnalnya balance', $seimbang($j1));
    $ok('nilai PPN tersimpan pada invoice-nya', (int) round((float) $inv1->ppn_amount) === 110, (string) $inv1->ppn_amount);

    echo "\n=== 2. INCLUDE PPN — layar sudah membaginya; nilai DPP yang masuk sudah bersih ===\n";

    /* Include ditangani di layar dengan membagi Nilai Transaksi ÷ (1 + tarif). Yang sampai ke
       server karena itu sudah berupa DPP bersih — 901 dengan PPN 99, total 1.000. */
    [, $j2] = $daftar([
        'expense_account_id' => $AKUN_BEBAN, 'payable_account_id' => $AKUN_HUTANG,
        'dpp' => 901, 'ppn_mode' => 'with', 'ppn_pct' => 11, 'ppn_base' => 901,
    ]);
    $ok('Dr beban = 901 (sudah berkurang sebesar PPN)', ($j2[$AKUN_BEBAN]['debit'] ?? 0) === 901, json_encode($j2[$AKUN_BEBAN] ?? null));
    $ok('Dr PPN Masukan = 99', ($j2[$AKUN_PPN]['debit'] ?? 0) === 99, json_encode($j2[$AKUN_PPN] ?? null));
    $ok('Cr Hutang Usaha = 1.000', ($j2[$AKUN_HUTANG]['credit'] ?? 0) === 1000, json_encode($j2[$AKUN_HUTANG] ?? null));
    $ok('jurnalnya balance', $seimbang($j2));

    echo "\n=== 3. PPh DIPOTONG — Hutang Usaha berkurang sebesar PPh ===\n";

    [, $j3] = $daftar([
        'expense_account_id' => $AKUN_BEBAN, 'payable_account_id' => $AKUN_HUTANG,
        'dpp' => 901, 'ppn_mode' => 'with', 'ppn_pct' => 11, 'ppn_base' => 901,
        'pph_mode' => 'withheld', 'pph_type' => 'pph23', 'pph_pct' => 2, 'pph_base' => 901,
        'pph_account_id' => $AKUN_PPH23,
    ]);
    $ok('Cr Hutang PPh 23 = 18', ($j3[$AKUN_PPH23]['credit'] ?? 0) === 18, json_encode($j3[$AKUN_PPH23] ?? null));
    $ok('Cr Hutang Usaha BERKURANG sebesar PPh → 982',
        ($j3[$AKUN_HUTANG]['credit'] ?? 0) === 982, json_encode($j3[$AKUN_HUTANG] ?? null));
    $ok('tidak ada baris Biaya PPh pada mode dipotong', ! isset($j3[$AKUN_PPH_BIAYA]), json_encode($j3[$AKUN_PPH_BIAYA] ?? null));
    $ok('jurnalnya balance', $seimbang($j3));

    echo "\n=== 4. PPh DITANGGUNG — Biaya PPh & Hutang PPh, Hutang Usaha TIDAK berubah ===\n";

    [, $j4] = $daftar([
        'expense_account_id' => $AKUN_BEBAN, 'payable_account_id' => $AKUN_HUTANG,
        'dpp' => 901, 'ppn_mode' => 'with', 'ppn_pct' => 11, 'ppn_base' => 901,
        'pph_mode' => 'borne', 'pph_type' => 'pph23', 'pph_pct' => 2, 'pph_base' => 901,
        'pph_account_id' => $AKUN_PPH23,
    ]);
    $ok('Dr Biaya PPh = 18', ($j4[$AKUN_PPH_BIAYA]['debit'] ?? 0) === 18, json_encode($j4[$AKUN_PPH_BIAYA] ?? null));
    // Bukan sekadar "ada baris biaya": akunnya harus BIAYA PPH 23, sejenis dengan Hutang PPh 23
    // di sebelahnya. Memakai akun beban umum tetap balance dan tetap 18 — tidak ada yang memberi tahu.
    $ok('akunnya BIAYA PPH 23, bukan beban umum',
        BsAccount::whereKey($AKUN_PPH_BIAYA)->value('code') === '70101010107',
        (string) BsAccount::whereKey($AKUN_PPH_BIAYA)->value('code'));
    $ok('Cr Hutang PPh 23 = 18', ($j4[$AKUN_PPH23]['credit'] ?? 0) === 18, json_encode($j4[$AKUN_PPH23] ?? null));
    $ok('Cr Hutang Usaha TETAP 1.000 — supplier dibayar penuh',
        ($j4[$AKUN_HUTANG]['credit'] ?? 0) === 1000, json_encode($j4[$AKUN_HUTANG] ?? null));
    $ok('jurnalnya balance', $seimbang($j4));

    echo "\n=== 5. STRUKTUR DARI WIZARD DITERIMA APA ADANYA ===\n";

    /* Persis bentuk yang kini dikirim layar untuk Exclude PPN + PPh dipotong:
       Dr beban 1.000 · Dr PPN 110 · Cr Hutang Usaha 1.090 · Cr Hutang PPh 20. */
    [, $j5] = $daftar([
        'expense_account_id' => $AKUN_BEBAN, 'payable_account_id' => $AKUN_HUTANG,
        'dpp' => 1000, 'ppn_mode' => 'with', 'ppn_pct' => 11, 'ppn_base' => 1000,
        'pph_mode' => 'withheld', 'pph_type' => 'pph23', 'pph_pct' => 2, 'pph_base' => 1000,
        'pph_account_id' => $AKUN_PPH23,
        'journal_lines' => [
            ['account_id' => $AKUN_BEBAN,  'debit' => 1000, 'credit' => 0,    'description' => 'Biaya'],
            ['account_id' => $AKUN_PPN,    'debit' => 110,  'credit' => 0,    'description' => 'PPN Masukan'],
            ['account_id' => $AKUN_HUTANG, 'debit' => 0,    'credit' => 1090, 'description' => 'Hutang supplier'],
            ['account_id' => $AKUN_PPH23,  'debit' => 0,    'credit' => 20,   'description' => 'PPh 23 dipotong'],
        ],
    ]);
    $ok('baris PPN dari wizard terposting', ($j5[$AKUN_PPN]['debit'] ?? 0) === 110, json_encode($j5[$AKUN_PPN] ?? null));
    $ok('Hutang Usaha mengikuti struktur wizard → 1.090',
        ($j5[$AKUN_HUTANG]['credit'] ?? 0) === 1090, json_encode($j5[$AKUN_HUTANG] ?? null));
    $ok('Hutang PPh dari wizard terposting', ($j5[$AKUN_PPH23]['credit'] ?? 0) === 20, json_encode($j5[$AKUN_PPH23] ?? null));
    $ok('jurnalnya balance', $seimbang($j5));

    // Struktur yang TIDAK balance ditolak — bukan diposting separuh.
    $ditolak = null;
    try {
        $daftar([
            'expense_account_id' => $AKUN_BEBAN, 'payable_account_id' => $AKUN_HUTANG,
            'dpp' => 1000, 'ppn_mode' => 'none',
            'journal_lines' => [
                ['account_id' => $AKUN_BEBAN,  'debit' => 1000, 'credit' => 0],
                ['account_id' => $AKUN_HUTANG, 'debit' => 0,    'credit' => 900],
            ],
        ]);
    } catch (\App\Exceptions\BusinessRuleException $e) {
        $ditolak = $e->errorCode();
    }
    $ok('struktur yang tidak balance ditolak', $ditolak === 'AP_JOURNAL_UNBALANCED', (string) $ditolak);

    echo "\n=== 6. TANPA PPN & PPh — dasarnya tidak berubah ===\n";

    [, $j6] = $daftar([
        'expense_account_id' => $AKUN_BEBAN, 'payable_account_id' => $AKUN_HUTANG,
        'dpp' => 1000, 'ppn_mode' => 'none',
    ]);
    $ok('Dr beban = 1.000', ($j6[$AKUN_BEBAN]['debit'] ?? 0) === 1000);
    $ok('Cr Hutang Usaha = 1.000', ($j6[$AKUN_HUTANG]['credit'] ?? 0) === 1000);
    $ok('tidak ada baris PPN', ! isset($j6[$AKUN_PPN]));
    $ok('jurnalnya balance', $seimbang($j6));
} finally {
    DB::rollBack();
}

echo "\n────────────────────────────────\n";
echo "LULUS: $lulus   GAGAL: $gagal\n";
echo $gagal === 0 ? "SEMUA UJI LULUS\n" : "ADA YANG GAGAL\n";

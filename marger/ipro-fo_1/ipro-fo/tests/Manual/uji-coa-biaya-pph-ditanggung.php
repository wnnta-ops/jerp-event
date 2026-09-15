<?php

/**
 * Akun BEBAN untuk PPh DITANGGUNG mengikuti JENIS PPh — sisi server.
 *
 *   PPh 21          → BIAYA PPH 21        (70101010109)
 *   PPh 23          → BIAYA PPH 23        (70101010107)
 *   PPh Pasal 4(2)  → BIAYA PPH 4 AYAT 2  (70101010108)
 *
 * Sisi layar diuji di tests/Manual/uji-coa-biaya-pph-ditanggung.cjs. Berkas ini menutup dua ujung
 * yang tidak bisa dilihat dari sana:
 *
 *  1. JURNAL YANG BENAR-BENAR TERPOSTING. Jalur otomatis RegisterApInvoice menyusun jurnalnya
 *     sendiri saat struktur tidak dikirim (API, skrip, atau tab yang barisnya terlanjur dihapus).
 *  2. LAYAR DAN SERVER HARUS MEMETAKAN KE AKUN YANG SAMA. Aturannya hidup di dua tempat; bila
 *     berbeda, invoice yang sama berjurnal berbeda tergantung dari pintu mana ia masuk — dan
 *     dua-duanya tetap balance, jadi tidak ada satu pun galat yang memberi tahu.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-coa-biaya-pph-ditanggung.php';"
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

/** Kode akun yang diminta, ditulis LITERAL di sini. Membacanya dari config akan membandingkan
 *  config dengan dirinya sendiri, dan salah ketik pada config-nya tetap lulus. */
$DIMINTA = [
    'pph21'  => ['70101010109', 'BIAYA PPH 21'],
    'pph23'  => ['70101010107', 'BIAYA PPH 23'],
    'pph4_2' => ['70101010108', 'BIAYA PPH 4 AYAT 2'],
];
/** Hutang PPh pasangannya — dipakai skenario posting. */
$HUTANG_PPH = ['pph21' => '20104040402', 'pph23' => '20104040403', 'pph4_2' => '20104040401'];

$akun = fn (string $kode) => BsAccount::where('code', $kode)->value('id');

echo "\n=== 1. Ketiga akun ada, aktif, dan bisa dijurnal ===\n";

foreach ($DIMINTA as $jenis => [$kode, $nama]) {
    $a = BsAccount::where('code', $kode)->first();
    $ok("akun $kode ada", (bool) $a, 'tidak ada di CoA');
    if (! $a) { continue; }
    $ok("  namanya $nama", strcasecmp(trim((string) $a->name), $nama) === 0, (string) $a->name);
    $ok('  aktif', (bool) $a->is_active);
    // Tanpa is_postable, barisnya ditolak saat posting — jurnalnya gagal, bukan salah diam-diam.
    $ok('  bisa dijurnal (is_postable)', (bool) $a->is_postable);
}

echo "\n=== 2. Config memetakan per jenis, bukan satu akun untuk semua ===\n";

$peta = config('business_support.gl_accounts.pph_borne_expense');
$ok('config berbentuk peta per jenis', is_array($peta), gettype($peta));
foreach ($DIMINTA as $jenis => [$kode, $nama]) {
    $ok("config $jenis → $kode", ($peta[$jenis] ?? null) === $kode, (string) ($peta[$jenis] ?? 'tidak ada'));
}
$ok('ketiga kodenya berbeda', count(array_unique(array_values((array) $peta))) === 3, json_encode($peta));
$ok('cadangan tetap akun beban umum lama',
    config('business_support.gl_accounts.pph_borne_expense_default') === '50302020228',
    (string) config('business_support.gl_accounts.pph_borne_expense_default'));

echo "\n=== 3. Aksi server memetakan persis seperti config ===\n";

/* akunBiayaPph() private — dipanggil lewat Reflection, bukan disalin ulang logikanya ke sini.
   Menyalinnya berarti menguji salinan, dan salinan itu akan tetap hijau saat aslinya berubah. */
$aksi = app(RegisterApInvoice::class);
$rm = new ReflectionMethod(RegisterApInvoice::class, 'akunBiayaPph');
$rm->setAccessible(true);
$petaServer = fn (?string $j) => $rm->invoke($aksi, $j);

foreach ($DIMINTA as $jenis => [$kode, $nama]) {
    $ok("server $jenis → $kode", $petaServer($jenis) === $akun($kode),
        'dapat id '.json_encode($petaServer($jenis)).', diharapkan '.json_encode($akun($kode)));
}
$ok('server: ketiganya berbeda',
    count(array_unique(array_map($petaServer, array_keys($DIMINTA)))) === 3);
$ok('server: jenis kosong → cadangan', $petaServer(null) === $akun('50302020228'), json_encode($petaServer(null)));
$ok('server: jenis asing → cadangan', $petaServer('pph_entah') === $akun('50302020228'));
$ok('server: tidak satu pun jenis dikenal jatuh ke cadangan',
    ! in_array($akun('50302020228'), array_map($petaServer, array_keys($DIMINTA)), true));

echo "\n=== 4. Layar & server memetakan ke akun yang SAMA ===\n";

/* Prop pphBorneDefaults-lah yang dipakai layar. Diambil dengan MEMANGGIL controllernya, bukan
   dihitung ulang di sini: menyalin ekspresinya berarti membandingkan config dengan dirinya
   sendiri, dan controller yang lupa mengirim propnya tetap lulus. (Versi pertama uji ini memang
   begitu, dan uji mutasi menangkapnya.) Lalu disandingkan dengan hasil aksi server — pemeriksaan
   yang paling menentukan: keduanya boleh saja benar sendiri-sendiri tetapi berbeda satu sama lain. */
$reqForm = \Illuminate\Http\Request::create('/business-support/payable/create', 'GET');
$reqForm->setUserResolver(fn () => $user);
$reqForm->headers->set('X-Inertia', 'true');
$reqForm->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());
$propsForm = json_decode(
    (string) app(\App\Http\Controllers\Web\BusinessSupport\Payable\ApInvoiceController::class)
        ->create($reqForm)->toResponse($reqForm)->getContent(),
    true
)['props'] ?? [];
$propControllerid = $propsForm['pphBorneDefaults'] ?? [];

$ok('controller benar-benar mengirim prop pphBorneDefaults',
    is_array($propControllerid) && $propControllerid !== [],
    'layar tidak menerima pemetaan apa pun: '.json_encode($propControllerid));
$ok('propnya berisi ketiga jenis PPh',
    count(array_intersect(array_keys($propControllerid), array_keys($DIMINTA))) === 3,
    json_encode(array_keys($propControllerid)));

foreach (array_keys($DIMINTA) as $jenis) {
    $ok("layar & server sepakat untuk $jenis",
        ($propControllerid[$jenis] ?? null) === $petaServer($jenis),
        'layar='.json_encode($propControllerid[$jenis] ?? null).' server='.json_encode($petaServer($jenis)));
    $ok("  dan keduanya bukan null", ($propControllerid[$jenis] ?? null) !== null);
}

echo "\n=== 5. Jurnal yang BENAR-BENAR terposting, per jenis PPh ===\n";

DB::beginTransaction();

try {
    $AKUN_HUTANG = $akun('20101010101');
    $AKUN_BEBAN  = BsAccount::where('is_postable', true)->where('type', 'expense')
        ->whereNotIn('id', array_filter(array_map(fn ($d) => $akun($d[0]), $DIMINTA)))
        ->orderBy('code')->value('id');
    $ok('akun Utang Usaha & akun beban pembanding ada', (bool) $AKUN_HUTANG && (bool) $AKUN_BEBAN);

    $supplier = BsSupplier::create([
        'code' => 'UJI-PPH-'.substr(md5((string) mt_rand()), 0, 6),
        'name' => 'UJI Supplier PPh Ditanggung', 'is_active' => true,
    ]);

    $aksiPost = app(RegisterApInvoice::class);
    $no = 0;
    $daftar = function (array $ubah) use ($aksiPost, $supplier, $user, &$no) {
        $inv = $aksiPost->execute(array_merge([
            'supplier_id'      => $supplier->id,
            'supplier_invoice_no' => 'UJI-PPH-'.(++$no),
            'invoice_date'     => now()->toDateString(),
            'receive_date'     => now()->toDateString(),
            'term_days'        => 30,
            'description'      => 'Uji akun biaya PPh ditanggung',
            'ppn_mode'         => 'none',
        ], $ubah), $user->id);

        $baris = [];
        foreach (BsJournal::find($inv->journal_id)?->lines ?? [] as $l) {
            $baris[$l->account_id] = ['debit' => (int) round($l->debit), 'credit' => (int) round($l->credit)];
        }

        return $baris;
    };

    foreach ($DIMINTA as $jenis => [$kode, $nama]) {
        $idBiaya  = $akun($kode);
        $idHutang = $akun($HUTANG_PPH[$jenis]);

        // Tanpa journal_lines → jalur OTOMATIS server yang dipakai (inilah yang diuji di sini).
        $j = $daftar([
            'expense_account_id' => $AKUN_BEBAN, 'payable_account_id' => $AKUN_HUTANG,
            'dpp' => 1000,
            'pph_mode' => 'borne', 'pph_type' => $jenis, 'pph_pct' => 2, 'pph_base' => 1000,
            'pph_account_id' => $idHutang,
        ]);

        echo "  — $jenis —\n";
        $ok("  Dr $nama = 20", ($j[$idBiaya]['debit'] ?? 0) === 20, json_encode($j[$idBiaya] ?? null));
        $ok('  Cr Hutang PPh pasangannya = 20', ($j[$idHutang]['credit'] ?? 0) === 20, json_encode($j[$idHutang] ?? null));
        // Yang paling mudah luput: baris bebannya TIDAK boleh menumpang di akun beban umum lama.
        $ok('  tidak menumpuk di akun beban umum (50302020228)',
            ! isset($j[$akun('50302020228')]), json_encode($j[$akun('50302020228')] ?? null));
        $ok('  Cr Hutang Usaha TETAP 1.000 — supplier dibayar penuh',
            ($j[$AKUN_HUTANG]['credit'] ?? 0) === 1000, json_encode($j[$AKUN_HUTANG] ?? null));
        $d = array_sum(array_column($j, 'debit'));
        $k = array_sum(array_column($j, 'credit'));
        $ok('  jurnalnya balance', $d === $k, "D=$d K=$k");
    }

    echo "\n=== 6. Jenis PPh kosong tetap membentuk baris (jurnal tidak boleh pincang) ===\n";

    $jKosong = $daftar([
        'expense_account_id' => $AKUN_BEBAN, 'payable_account_id' => $AKUN_HUTANG,
        'dpp' => 1000,
        'pph_mode' => 'borne', 'pph_type' => null, 'pph_pct' => 2, 'pph_base' => 1000,
        'pph_account_id' => $akun('20104040402'),
    ]);
    $ok('baris Biaya PPh tetap terbentuk di akun cadangan',
        ($jKosong[$akun('50302020228')]['debit'] ?? 0) === 20, json_encode($jKosong[$akun('50302020228')] ?? null));
    $dk = array_sum(array_column($jKosong, 'debit'));
    $kk = array_sum(array_column($jKosong, 'credit'));
    $ok('jurnalnya balance', $dk === $kk, "D=$dk K=$kk");

    echo "\n=== 7. Mode DIPOTONG tidak menyentuh akun beban PPh mana pun ===\n";

    $jPotong = $daftar([
        'expense_account_id' => $AKUN_BEBAN, 'payable_account_id' => $AKUN_HUTANG,
        'dpp' => 1000,
        'pph_mode' => 'withheld', 'pph_type' => 'pph23', 'pph_pct' => 2, 'pph_base' => 1000,
        'pph_account_id' => $akun('20104040403'),
    ]);
    foreach ($DIMINTA as $jenis => [$kode, $nama]) {
        $ok("  tidak ada baris $nama", ! isset($jPotong[$akun($kode)]), json_encode($jPotong[$akun($kode)] ?? null));
    }
    $ok('  Cr Hutang Usaha berkurang sebesar PPh → 980',
        ($jPotong[$AKUN_HUTANG]['credit'] ?? 0) === 980, json_encode($jPotong[$AKUN_HUTANG] ?? null));

    echo "\n────────────────────────────────\n";
    echo "LULUS: $lulus   GAGAL: $gagal\n";
    echo $gagal === 0 ? "SEMUA UJI LULUS\n" : "ADA YANG GAGAL\n";
} finally {
    DB::rollBack();
}
